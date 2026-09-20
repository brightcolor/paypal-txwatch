# Publikumsauswertung — Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eine Seite „Publikum", die für eine Auswahl von Veranstaltungen zeigt, welche Ticketkäufer sich überschneiden, wer wie oft kauft und welche weiteren Muster im Bestand stecken.

**Architecture:** Eine abgeleitete Tabelle `pretix_positions` wird beim pretix-Import je aktiver Ticketposition mitgeschrieben und ist über `pretix:rebuild-positions` jederzeit neu baubar. Darüber liegt ein Dienst `App\Services\Audience`, dessen Klassen alle durch `AudienceQuery` gehen — dort sitzt die Mandantensperre. Die Filament-Seite `AudiencePage` liest ausschließlich aus diesen Klassen.

**Tech Stack:** Laravel 13, Filament 3.3, PHP 8.4, PostgreSQL in Produktion, SQLite im Test, PHPUnit 12, Spatie Permission, maatwebsite/excel.

**Spec:** [docs/superpowers/specs/2026-09-21-publikumsauswertung-design.md](../specs/2026-09-21-publikumsauswertung-design.md)

## Global Constraints

- **Tests laufen mit `php artisan test`** aus dem Projektverzeichnis, SQLite in-memory (`phpunit.xml`).
- **Code auf Englisch, Ausgabe auf Deutsch, echte Umlaute.** Kommentare erklären das Warum, so wie im Bestand.
- **Kein Tailwind-Build.** Eigenes Styling kommt als echtes CSS nach `resources/views/filament/adminlte-theme.blade.php`. Arbitrary-Klassen wie `min-w-[42rem]` existieren im ausgelieferten CSS nicht und bleiben wirkungslos.
- **Filament injiziert Closure-Argumente per Parametername.** Spalten-Closures verwenden `$state`, Filter-Closures `$query`. Ein falscher Name gibt einen Builder ohne Model und erzeugt einen 500er.
- **Blätterung:** Listenseiten von Resources binden `App\Filament\Concerns\ClampsRecordsPerPageOnReload` ein. Die Käuferliste ist eine gruppierte Abfrage auf einer eigenen Seite und begrenzt ihre Seitengrößen stattdessen direkt auf `[25, 50, 100, 200]` — damit gibt es keine Auswahl, die geklemmt werden müsste.
- **Die Mandantensperre wird genau einmal angewandt**, in `AudienceQuery`. Keine Auswertung baut ihren Query an dieser Klasse vorbei.
- **Textsuche immer mit `LOWER(...) LIKE`.** `LIKE` unterscheidet auf PostgreSQL Groß- und Kleinschreibung, auf SQLite nicht.
- **Spaltenbreiten großzügig wählen.** SQLite verschweigt zu enge `varchar`-Längen, PostgreSQL weist den Insert ab.
- **`COUNT(DISTINCT …)` über Bestellungen immer über `event_slug || '/' || order_code`.** pretix-Bestellnummern sind je Veranstaltung eindeutig, über Veranstaltungen hinweg können sie sich wiederholen. Der Operator `||` funktioniert auf SQLite und PostgreSQL.
- **Version steht in `config/version.php`**, Changelog in `CHANGELOG.md`.
- **Keine echten Personendaten in Tests.** Alle Namen und Adressen sind erfunden.

---

### Task 1: Tabelle und Model für Ticketpositionen

**Files:**
- Create: `database/migrations/2026_09_21_100000_create_pretix_positions_table.php`
- Create: `app/Models/PretixPosition.php`
- Test: `tests/Feature/Pretix/PretixPositionsSchemaTest.php`

**Interfaces:**
- Consumes: nichts
- Produces: Tabelle `pretix_positions`; Model `App\Models\PretixPosition` mit den Feldern `pretix_connection_id`, `pretix_order_id`, `event_slug`, `order_code`, `order_status`, `payment_provider`, `buyer_email`, `buyer_name`, `position_id`, `item_id`, `price`, `voucher`, `attendee_name`, `zipcode`, `city`, `country`, `ordered_at`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Pretix/PretixPositionsSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Pretix;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The column widths have to fit what pretix can send.
 *
 * READ FROM THE MIGRATION, not written and read back: SQLite ignores a varchar
 * length, so a round trip would stay green no matter how narrow the column is and
 * PostgreSQL would reject the insert in production. That is the same class of
 * failure as the `source_format` column on 17.08.2026.
 */
class PretixPositionsSchemaTest extends TestCase
{
    /** The shortest width each column may have, from pretix' own field lengths. */
    private const MINIMUM_WIDTHS = [
        'event_slug' => 255,
        'order_code' => 64,
        'payment_provider' => 64,
        'buyer_email' => 255,
        'buyer_name' => 255,
        'voucher' => 255,
        'attendee_name' => 255,
        'zipcode' => 32,
        'city' => 128,
    ];

    public function test_every_string_column_is_wide_enough(): void
    {
        $migration = File::get(database_path('migrations/2026_09_21_100000_create_pretix_positions_table.php'));

        foreach (self::MINIMUM_WIDTHS as $column => $minimum) {
            $found = preg_match(
                sprintf("/->string\\('%s',\\s*(\\d+)\\)/", preg_quote($column, '/')),
                $migration,
                $treffer,
            );

            $this->assertSame(1, $found, "Spalte {$column} wird in der Migration ohne ausdrückliche Länge angelegt.");
            $this->assertGreaterThanOrEqual(
                $minimum,
                (int) $treffer[1],
                "Spalte {$column} ist zu schmal für das, was pretix liefern kann.",
            );
        }
    }

    public function test_table_and_model_agree_on_the_fillable_columns(): void
    {
        $model = new \App\Models\PretixPosition();

        foreach ($model->getFillable() as $column) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('pretix_positions', $column),
                "Das Model nennt {$column}, die Tabelle hat diese Spalte nicht.",
            );
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PretixPositionsSchemaTest`
Expected: FAIL — die Migrationsdatei fehlt, `File::get` wirft `FileNotFoundException`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_21_100000_create_pretix_positions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per ACTIVE ticket position, derived from the stored pretix orders.
 *
 * WHY A TABLE AND NOT A QUERY OVER raw_payload: the audience questions are
 * group-by questions - who bought at which events, how often, which ticket type. In
 * the payload every one of those means unpacking 1458 JSON documents per screen, and
 * a sortable, paginated buyer list on top of that is hand-built. As a table it is
 * plain SQL and an ordinary Filament table.
 *
 * IT IS DERIVED, never edited: `pretix:rebuild-positions` builds it from the orders
 * at any time, so there is no second truth to keep in sync - only a copy that can be
 * thrown away.
 *
 * CANCELLED POSITIONS STAY OUT. This table is the audience; a cancelled ticket is a
 * person who will not be there. Cancellation rates are counted on the order level,
 * where the status lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pretix_positions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('pretix_connection_id')->nullable();
            $table->unsignedBigInteger('pretix_order_id');

            $table->string('event_slug', 255);
            $table->string('order_code', 64);
            $table->string('order_status', 8)->nullable();
            $table->string('payment_provider', 64)->nullable();

            // The buyer's identity. NULL when the order carries no address: those
            // tickets still count, but they must not bundle into one phantom person.
            $table->string('buyer_email', 255)->nullable();
            $table->string('buyer_name', 255)->nullable();

            // pretix' own ids: the position and the ticket type it refers to.
            $table->unsignedBigInteger('position_id');
            $table->unsignedBigInteger('item_id');

            $table->decimal('price', 10, 2)->default(0);
            $table->string('voucher', 255)->nullable();
            $table->string('attendee_name', 255)->nullable();

            $table->string('zipcode', 32)->nullable();
            $table->string('city', 128)->nullable();
            $table->string('country', 8)->nullable();

            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();

            // A second import must update, never duplicate.
            $table->unique(
                ['pretix_connection_id', 'event_slug', 'order_code', 'position_id'],
                'pretix_positions_unique',
            );

            $table->index(['event_slug', 'buyer_email']);
            $table->index('buyer_email');
            $table->index('ordered_at');
            $table->index('item_id');
            $table->index('pretix_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pretix_positions');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/PretixPosition.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One active ticket position of one pretix order.
 *
 * Derived data owned by pretix: rewritten on every import, never edited here. No
 * audit log for the same reason - an entry would record a copy, not a decision.
 */
class PretixPosition extends Model
{
    protected $fillable = [
        'pretix_connection_id',
        'pretix_order_id',
        'event_slug',
        'order_code',
        'order_status',
        'payment_provider',
        'buyer_email',
        'buyer_name',
        'position_id',
        'item_id',
        'price',
        'voucher',
        'attendee_name',
        'zipcode',
        'city',
        'country',
        'ordered_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'ordered_at' => 'datetime',
            'position_id' => 'integer',
            'item_id' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PretixOrder::class, 'pretix_order_id');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PretixPositionsSchemaTest`
Expected: PASS, 2 Tests.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_21_100000_create_pretix_positions_table.php app/Models/PretixPosition.php tests/Feature/Pretix/PretixPositionsSchemaTest.php
git commit -m "Tabelle und Model fuer abgeleitete Ticketpositionen"
```

---

### Task 2: PositionWriter — Zeilen aus einer Bestellung

**Files:**
- Create: `app/Services/Pretix/PositionWriter.php`
- Create: `tests/Support/MakesPretixData.php`
- Test: `tests/Feature/Pretix/PositionWriterTest.php`

**Interfaces:**
- Consumes: `App\Models\PretixPosition` aus Task 1
- Produces:
  - `App\Services\Pretix\PositionWriter::rowsFor(PretixOrder $order): array<int, array<string, mixed>>`
  - `App\Services\Pretix\PositionWriter::write(PretixOrder $order): int` — löscht die Zeilen der Bestellung und schreibt sie neu, gibt die Zeilenzahl zurück
  - `Tests\Support\MakesPretixData` mit `pretixConnection()`, `ticketType()`, `pretixOrder()`

- [ ] **Step 1: Write the test helper**

Create `tests/Support/MakesPretixData.php`:

```php
<?php

namespace Tests\Support;

use App\Models\PretixConnection;
use App\Models\PretixItem;
use App\Models\PretixOrder;
use App\Services\Pretix\PositionWriter;

/**
 * Builds pretix orders the way the importer stores them, so tests exercise the
 * real payload shape. All names and addresses are invented.
 */
trait MakesPretixData
{
    private ?PretixConnection $pretixConnection = null;

    protected function pretixConnection(): PretixConnection
    {
        return $this->pretixConnection ??= PretixConnection::create([
            'name' => 'Verein',
            'base_url' => 'https://pretix.eu',
            'organizer_slug' => 'verein',
            'api_token' => 'tok',
            'is_active' => true,
        ]);
    }

    protected function ticketType(string $eventSlug, int $itemId, string $name): PretixItem
    {
        return PretixItem::create([
            'pretix_connection_id' => $this->pretixConnection()->id,
            'event_slug' => $eventSlug,
            'item_id' => $itemId,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<int, array{item: int, price?: float, attendee?: string, voucher?: string, canceled?: bool}>  $positions
     * @param  array<string, string>  $address
     */
    protected function pretixOrder(
        string $eventSlug,
        string $code,
        ?string $email,
        array $positions,
        string $status = 'p',
        string $orderedAt = '2026-07-05 12:00:00',
        array $address = ['name' => 'Erika Beispiel', 'zipcode' => '23966', 'city' => 'Wismar', 'country' => 'DE'],
        string $provider = 'paypal',
        bool $writePositions = true,
    ): PretixOrder {
        $nummer = 0;

        $order = PretixOrder::create([
            'pretix_connection_id' => $this->pretixConnection()->id,
            'event_slug' => $eventSlug,
            'order_code' => $code,
            'status' => $status,
            'payment_provider' => $provider,
            'email' => $email,
            'total' => array_sum(array_map(fn (array $p) => $p['price'] ?? 50.0, $positions)),
            'currency' => 'EUR',
            'order_datetime' => $orderedAt,
            'url' => 'https://pretix.eu/x/' . $code,
            'raw_payload' => [
                'code' => $code,
                'invoice_address' => $address,
                'positions' => array_map(function (array $p) use (&$nummer) {
                    $nummer++;

                    return [
                        'id' => 1000 + $nummer,
                        'positionid' => $nummer,
                        'item' => $p['item'],
                        'price' => $p['price'] ?? 50.0,
                        'attendee_name' => $p['attendee'] ?? null,
                        'voucher' => $p['voucher'] ?? null,
                        'canceled' => $p['canceled'] ?? false,
                    ];
                }, $positions),
            ],
        ]);

        if ($writePositions) {
            app(PositionWriter::class)->write($order);
        }

        return $order;
    }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Pretix/PositionWriterTest.php`:

```php
<?php

namespace Tests\Feature\Pretix;

use App\Models\PretixPosition;
use App\Services\Pretix\PositionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

/**
 * The derived table has to say the same thing as the payload it came from.
 *
 * The case that matters most is the SECOND import: pretix marks a position as
 * cancelled, and the row has to disappear. Writing only additively would leave a
 * ticket in the audience that nobody holds any more, and every count above it would
 * stay quietly wrong.
 */
class PositionWriterTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    public function test_it_writes_one_row_per_active_position(): void
    {
        $order = $this->pretixOrder('sommerfest', 'ABCDE', 'A.Mueller@example.test', [
            ['item' => 3, 'price' => 49.5, 'attendee' => 'Lena Beispiel'],
            ['item' => 4, 'price' => 20.0],
            ['item' => 4, 'price' => 20.0, 'canceled' => true],
        ], writePositions: false);

        $geschrieben = app(PositionWriter::class)->write($order);

        $this->assertSame(2, $geschrieben);
        $this->assertSame(2, PretixPosition::count());

        $erste = PretixPosition::orderBy('position_id')->first();
        $this->assertSame('sommerfest', $erste->event_slug);
        $this->assertSame('ABCDE', $erste->order_code);
        $this->assertSame('p', $erste->order_status);
        $this->assertSame('a.mueller@example.test', $erste->buyer_email);
        $this->assertSame('Erika Beispiel', $erste->buyer_name);
        $this->assertSame('Lena Beispiel', $erste->attendee_name);
        $this->assertSame(3, $erste->item_id);
        $this->assertSame('49.50', $erste->price);
        $this->assertSame('23966', $erste->zipcode);
        $this->assertSame('Wismar', $erste->city);
    }

    public function test_a_cancelled_position_disappears_on_the_next_write(): void
    {
        $order = $this->pretixOrder('sommerfest', 'ABCDE', 'k@example.test', [
            ['item' => 3],
            ['item' => 4],
        ]);

        $this->assertSame(2, PretixPosition::count());

        $payload = $order->raw_payload;
        $payload['positions'][1]['canceled'] = true;
        $order->update(['raw_payload' => $payload]);

        app(PositionWriter::class)->write($order->refresh());

        $this->assertSame(1, PretixPosition::count());
        $this->assertSame(3, PretixPosition::first()->item_id);
    }

    public function test_an_order_without_an_address_gets_no_buyer(): void
    {
        $order = $this->pretixOrder('sommerfest', 'LEER1', '  ', [['item' => 3]], writePositions: false);

        app(PositionWriter::class)->write($order);

        $this->assertNull(PretixPosition::first()->buyer_email);
    }

    public function test_writing_twice_leaves_the_same_rows(): void
    {
        $order = $this->pretixOrder('sommerfest', 'ABCDE', 'k@example.test', [['item' => 3], ['item' => 4]]);

        app(PositionWriter::class)->write($order);

        $this->assertSame(2, PretixPosition::count());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=PositionWriterTest`
Expected: FAIL mit „Class App\Services\Pretix\PositionWriter does not exist".

- [ ] **Step 4: Write the implementation**

Create `app/Services/Pretix/PositionWriter.php`:

```php
<?php

namespace App\Services\Pretix;

use App\Models\PretixOrder;
use App\Models\PretixPosition;
use Illuminate\Support\Facades\DB;

/**
 * Turns one stored pretix order into the rows of `pretix_positions`.
 *
 * DELETE THEN INSERT, in one transaction: pretix sends the full order on every
 * change, so the payload is the truth and the rows are a projection of it. Updating
 * in place would have to guess which of the stored rows the payload no longer
 * contains - the delete answers that without guessing.
 */
class PositionWriter
{
    /** Rewrites the rows of one order. Returns how many were written. */
    public function write(PretixOrder $order): int
    {
        $rows = $this->rowsFor($order);

        DB::transaction(function () use ($order, $rows) {
            PretixPosition::query()->where('pretix_order_id', $order->id)->delete();

            if ($rows !== []) {
                PretixPosition::query()->insert($rows);
            }
        });

        return count($rows);
    }

    /**
     * The rows one order produces, without touching the database.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rowsFor(PretixOrder $order): array
    {
        $raw = $order->raw_payload ?? [];
        $addresse = is_array($raw['invoice_address'] ?? null) ? $raw['invoice_address'] : [];
        $jetzt = now();

        $mail = mb_strtolower(trim((string) $order->email));
        $mail = $mail === '' ? null : $mail;

        $rows = [];

        foreach ($raw['positions'] ?? [] as $position) {
            if (($position['canceled'] ?? false) === true) {
                continue;
            }

            $rows[] = [
                'pretix_connection_id' => $order->pretix_connection_id,
                'pretix_order_id' => $order->id,
                'event_slug' => $order->event_slug,
                'order_code' => $order->order_code,
                'order_status' => $order->status,
                'payment_provider' => $order->payment_provider,
                'buyer_email' => $mail,
                'buyer_name' => $this->text($addresse['name'] ?? null),
                'position_id' => (int) ($position['id'] ?? 0),
                'item_id' => (int) ($position['item'] ?? 0),
                'price' => (float) ($position['price'] ?? 0),
                // pretix sends the voucher's id here, not the code that was typed in.
                'voucher' => $this->text($position['voucher'] ?? null),
                'attendee_name' => $this->text($position['attendee_name'] ?? null),
                // The invoice address first: it is the buyer. The position carries an
                // address only when the ticket itself was addressed separately.
                'zipcode' => $this->text($addresse['zipcode'] ?? null) ?? $this->text($position['zipcode'] ?? null),
                'city' => $this->text($addresse['city'] ?? null) ?? $this->text($position['city'] ?? null),
                'country' => $this->text($addresse['country'] ?? null) ?? $this->text($position['country'] ?? null),
                'ordered_at' => $order->order_datetime,
                'created_at' => $jetzt,
                'updated_at' => $jetzt,
            ];
        }

        return $rows;
    }

    /** A trimmed string, or null when there is nothing in it. */
    private function text(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $getrimmt = trim($value);

        return $getrimmt === '' ? null : $getrimmt;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PositionWriterTest`
Expected: PASS, 4 Tests.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Pretix/PositionWriter.php tests/Support/MakesPretixData.php tests/Feature/Pretix/PositionWriterTest.php
git commit -m "PositionWriter schreibt aktive Ticketpositionen aus einer Bestellung"
```

---

### Task 3: Einhängen in den Import und Befehl zum Neuaufbau

**Files:**
- Modify: `app/Services/Pretix/PretixOrderImporter.php` (in `upsertOrder()`, direkt nach `PretixOrder::updateOrCreate`)
- Create: `app/Console/Commands/RebuildPretixPositions.php`
- Test: `tests/Feature/Pretix/RebuildPretixPositionsTest.php`

**Interfaces:**
- Consumes: `PositionWriter::write()` aus Task 2
- Produces: Befehl `pretix:rebuild-positions {--event=}`; jede importierte Bestellung erzeugt ihre Positionszeilen

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Pretix/RebuildPretixPositionsTest.php`:

```php
<?php

namespace Tests\Feature\Pretix;

use App\Models\PretixPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

/**
 * The rebuild has to be usable on a stock that was imported before the table
 * existed - that is the only way the 3359 positions already in production get in.
 */
class RebuildPretixPositionsTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    public function test_it_builds_the_rows_of_every_stored_order(): void
    {
        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3], ['item' => 4]], writePositions: false);
        $this->pretixOrder('winterball', 'BBBBB', 'b@example.test', [['item' => 7]], writePositions: false);

        $this->assertSame(0, PretixPosition::count());

        $this->artisan('pretix:rebuild-positions')->assertSuccessful();

        $this->assertSame(3, PretixPosition::count());
    }

    public function test_it_can_be_limited_to_one_event(): void
    {
        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3]], writePositions: false);
        $this->pretixOrder('winterball', 'BBBBB', 'b@example.test', [['item' => 7]], writePositions: false);

        $this->artisan('pretix:rebuild-positions', ['--event' => 'winterball'])->assertSuccessful();

        $this->assertSame(1, PretixPosition::count());
        $this->assertSame('winterball', PretixPosition::first()->event_slug);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3], ['item' => 4]], writePositions: false);

        $this->artisan('pretix:rebuild-positions')->assertSuccessful();
        $this->artisan('pretix:rebuild-positions')->assertSuccessful();

        $this->assertSame(2, PretixPosition::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RebuildPretixPositionsTest`
Expected: FAIL mit „The command 'pretix:rebuild-positions' does not exist."

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/RebuildPretixPositions.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\PretixOrder;
use App\Services\Pretix\PositionWriter;
use Illuminate\Console\Command;

/**
 * Rebuilds `pretix_positions` from the stored orders.
 *
 * NEEDED because the table is derived: it starts empty, it has to be filled from a
 * stock that was imported long before it existed, and after any change to the
 * writer it has to be possible to throw the rows away and get them again.
 */
class RebuildPretixPositions extends Command
{
    protected $signature = 'pretix:rebuild-positions {--event= : Nur diese Veranstaltung (pretix-Slug)}';

    protected $description = 'Baut die Ticketpositionen aus den gespeicherten pretix-Bestellungen neu auf';

    public function handle(PositionWriter $writer): int
    {
        $slug = $this->option('event');

        $orders = PretixOrder::query()
            ->when($slug, fn ($query) => $query->where('event_slug', $slug))
            ->orderBy('id');

        $gelesen = 0;
        $geschrieben = 0;
        $ohnePositionen = 0;

        $orders->chunkById(200, function ($seite) use ($writer, &$gelesen, &$geschrieben, &$ohnePositionen) {
            foreach ($seite as $order) {
                $gelesen++;
                $zeilen = $writer->write($order);
                $geschrieben += $zeilen;

                if ($zeilen === 0) {
                    $ohnePositionen++;
                }
            }
        });

        $this->info(sprintf(
            '%d Bestellungen gelesen, %d Positionen geschrieben, %d Bestellungen ohne aktive Position.',
            $gelesen,
            $geschrieben,
            $ohnePositionen,
        ));

        if ($gelesen === 0) {
            $this->warn($slug
                ? "Zur Veranstaltung „{$slug}\" liegen keine Bestellungen vor. Prüfe den Slug in der Eventverwaltung."
                : 'Es liegen keine pretix-Bestellungen vor. Starte zuerst einen pretix-Import.');
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Hook the writer into the importer**

In `app/Services/Pretix/PretixOrderImporter.php`, in `upsertOrder()`, direkt nach der Zuweisung `$order = PretixOrder::updateOrCreate(...)` und vor `$after = OrderLog::snapshot($order->refresh());` einfügen:

```php
        /*
         * The derived positions travel with the order. Written here rather than in a
         * listener, because the order and its positions have to be right at the same
         * moment: every audience figure reads the positions, and an order that is
         * already updated while its positions still describe the previous state is a
         * wrong number that nothing complains about.
         */
        app(\App\Services\Pretix\PositionWriter::class)->write($order);
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter="RebuildPretixPositionsTest|PretixOrderImport"`
Expected: PASS. Falls ein bestehender Importer-Test rot wird, weil er eine Bestellung ohne `positions` im Payload anlegt: Der Writer schreibt dann null Zeilen — das ist richtig, der Test braucht keine Anpassung.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/RebuildPretixPositions.php app/Services/Pretix/PretixOrderImporter.php tests/Feature/Pretix/RebuildPretixPositionsTest.php
git commit -m "Import schreibt Ticketpositionen mit, Befehl baut sie neu auf"
```

---

### Task 4: Mandantensperre und AudienceQuery

**Files:**
- Modify: `app/Support/CustomerScope.php` (neue Methode `byEventSlug`)
- Create: `app/Services/Audience/AudienceQuery.php`
- Test: `tests/Feature/Audience/AudienceScopeTest.php`

**Interfaces:**
- Consumes: `PretixPosition` aus Task 1, `MakesPretixData` aus Task 2
- Produces:
  - `App\Support\CustomerScope::byEventSlug(Builder $query, string $column = 'event_slug'): Builder`
  - `App\Services\Audience\AudienceQuery` mit dem Konstruktor `__construct(array $eventSlugs = [], array $statuses = ['p'], array $itemIds = [], ?CarbonInterface $from = null, ?CarbonInterface $until = null)` und den Methoden `positions(): Builder`, `buyers(): Builder`, `visiblePositions(): Builder`, `orders(): Builder`, `ordersAnyStatus(): Builder`, `eventSlugs(): array`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Audience/AudienceScopeTest.php`:

```php
<?php

namespace Tests\Feature\Audience;

use App\Models\Customer;
use App\Models\Event;
use App\Models\User;
use App\Services\Audience\AudienceQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

/**
 * A promoter sees their own events and nothing else.
 *
 * THE COUNTER-TEST IS THE POINT: checking that their own event shows up proves
 * nothing about the scope - an unscoped query passes that just as well. The test
 * that matters is the foreign event that must be absent.
 */
class AudienceScopeTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    private Customer $eigener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->eigener = Customer::create(['name' => 'Verein Nord', 'is_active' => true]);
        $fremder = Customer::create(['name' => 'Verein Sued', 'is_active' => true]);

        Event::create(['customer_id' => $this->eigener->id, 'name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'is_active' => true]);
        Event::create(['customer_id' => $fremder->id, 'name' => 'Winterball', 'pretix_event_slug' => 'winterball', 'is_active' => true]);

        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3]]);
        $this->pretixOrder('winterball', 'BBBBB', 'b@example.test', [['item' => 7]]);
    }

    private function loginAs(string $role, ?int $customerId = null): void
    {
        $user = User::factory()->create(['customer_id' => $customerId]);
        $user->assignRole(Role::findByName($role));
        $this->actingAs($user);
    }

    public function test_an_admin_sees_every_event(): void
    {
        $this->loginAs('admin');

        $slugs = (new AudienceQuery())->positions()->pluck('event_slug')->unique()->sort()->values()->all();

        $this->assertSame(['sommerfest', 'winterball'], $slugs);
    }

    public function test_a_promoter_never_sees_a_foreign_event(): void
    {
        $this->loginAs('customer', $this->eigener->id);

        $slugs = (new AudienceQuery())->positions()->pluck('event_slug')->unique()->values()->all();

        $this->assertSame(['sommerfest'], $slugs);
    }

    public function test_a_promoter_asking_for_a_foreign_event_gets_nothing(): void
    {
        $this->loginAs('customer', $this->eigener->id);

        $this->assertSame(0, (new AudienceQuery(eventSlugs: ['winterball']))->positions()->count());
    }

    public function test_a_promoter_without_a_customer_sees_nothing(): void
    {
        $this->loginAs('customer');

        $this->assertSame(0, (new AudienceQuery())->positions()->count());
        $this->assertSame(0, (new AudienceQuery())->visiblePositions()->count());
        $this->assertSame(0, (new AudienceQuery())->orders()->count());
    }

    public function test_the_status_filter_defaults_to_paid(): void
    {
        $this->loginAs('admin');
        $this->pretixOrder('sommerfest', 'CCCCC', 'c@example.test', [['item' => 3]], status: 'c');

        $this->assertSame(2, (new AudienceQuery())->positions()->count());
        $this->assertSame(3, (new AudienceQuery(statuses: []))->positions()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AudienceScopeTest`
Expected: FAIL mit „Class App\Services\Audience\AudienceQuery does not exist".

- [ ] **Step 3: Extend CustomerScope**

In `app/Support/CustomerScope.php`, nach `byCustomerId()` einfügen:

```php
    /**
     * Scope a query that carries a pretix event slug to the customer's own events.
     *
     * A customer with no events gets an empty list and therefore no rows - that is
     * the same "sees nothing rather than everything" rule as activeCustomerId().
     */
    public static function byEventSlug(Builder $query, string $column = 'event_slug'): Builder
    {
        $customerId = static::activeCustomerId();

        if ($customerId !== null) {
            $slugs = \App\Models\Event::query()
                ->where('customer_id', $customerId)
                ->whereNotNull('pretix_event_slug')
                ->pluck('pretix_event_slug')
                ->all();

            $query->whereIn($column, $slugs);
        }

        return $query;
    }
```

- [ ] **Step 4: Write AudienceQuery**

Create `app/Services/Audience/AudienceQuery.php`:

```php
<?php

namespace App\Services\Audience;

use App\Models\PretixOrder;
use App\Models\PretixPosition;
use App\Support\CustomerScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The current selection of the audience screen, as one object.
 *
 * EVERY audience figure goes through here, and the customer scope is applied in
 * this one place. A check that sat on the screen instead would let any widget added
 * later reach past it - and a leak of one promoter's buyers into another promoter's
 * view is the one mistake this feature must not make.
 */
class AudienceQuery
{
    /** @var array<int, string> */
    public readonly array $eventSlugs;

    /** @var array<int, string> */
    public readonly array $statuses;

    /** @var array<int, int> */
    public readonly array $itemIds;

    public function __construct(
        array $eventSlugs = [],
        array $statuses = ['p'],
        array $itemIds = [],
        public readonly ?CarbonInterface $from = null,
        public readonly ?CarbonInterface $until = null,
    ) {
        $this->eventSlugs = array_values(array_filter(array_map('strval', $eventSlugs), fn ($s) => $s !== ''));
        $this->statuses = array_values(array_filter(array_map('strval', $statuses), fn ($s) => $s !== ''));
        $this->itemIds = array_values(array_map('intval', $itemIds));
    }

    /** The positions of the current selection. */
    public function positions(): Builder
    {
        $query = $this->visiblePositions();

        if ($this->eventSlugs !== []) {
            $query->whereIn('event_slug', $this->eventSlugs);
        }

        if ($this->statuses !== []) {
            $query->whereIn('order_status', $this->statuses);
        }

        if ($this->itemIds !== []) {
            $query->whereIn('item_id', $this->itemIds);
        }

        return $this->betweenDates($query, 'ordered_at');
    }

    /** The positions the user may see at all, ignoring the selection. */
    public function visiblePositions(): Builder
    {
        return CustomerScope::byEventSlug(PretixPosition::query());
    }

    /** The positions of the selection that carry a buyer. */
    public function buyers(): Builder
    {
        return $this->positions()->whereNotNull('buyer_email');
    }

    /** The orders of the selection. The ticket type filter cannot apply here. */
    public function orders(): Builder
    {
        $query = $this->ordersAnyStatus();

        if ($this->statuses !== []) {
            $query->whereIn('status', $this->statuses);
        }

        return $query;
    }

    /**
     * The orders of the selection, whatever their status.
     *
     * Needed by the cancellation rate: with the default filter on "paid" a cancelled
     * order would be filtered away before it could be counted as cancelled.
     */
    public function ordersAnyStatus(): Builder
    {
        $query = CustomerScope::byEventSlug(PretixOrder::query());

        if ($this->eventSlugs !== []) {
            $query->whereIn('event_slug', $this->eventSlugs);
        }

        return $this->betweenDates($query, 'order_datetime');
    }

    private function betweenDates(Builder $query, string $column): Builder
    {
        if ($this->from !== null) {
            $query->where($column, '>=', $this->from->copy()->startOfDay());
        }

        if ($this->until !== null) {
            $query->where($column, '<=', $this->until->copy()->endOfDay());
        }

        return $query;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AudienceScopeTest`
Expected: PASS, 5 Tests.

- [ ] **Step 6: Commit**

```bash
git add app/Support/CustomerScope.php app/Services/Audience/AudienceQuery.php tests/Feature/Audience/AudienceScopeTest.php
git commit -m "AudienceQuery buendelt die Auswahl und traegt die Mandantensperre"
```

---

### Task 5: Kopfzahlen

**Files:**
- Create: `app/Services/Audience/AudienceStats.php`
- Test: `tests/Feature/Audience/AudienceStatsTest.php`

**Interfaces:**
- Consumes: `AudienceQuery` aus Task 4
- Produces: `App\Services\Audience\AudienceStats::forSelection(AudienceQuery $query): array{buyers:int, tickets:int, orders:int, revenue:float, tickets_without_buyer:int, multi_event_buyers:int, returning_share:float}`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Audience/AudienceStatsTest.php`:

```php
<?php

namespace Tests\Feature\Audience;

use App\Models\User;
use App\Services\Audience\AudienceQuery;
use App\Services\Audience\AudienceStats;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

class AudienceStatsTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);
    }

    public function test_it_counts_buyers_tickets_orders_and_revenue(): void
    {
        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3, 'price' => 40.0], ['item' => 4, 'price' => 10.0]]);
        $this->pretixOrder('sommerfest', 'BBBBB', 'b@example.test', [['item' => 3, 'price' => 40.0]]);
        $this->pretixOrder('winterball', 'CCCCC', 'A@Example.test', [['item' => 7, 'price' => 25.0]]);

        $zahlen = app(AudienceStats::class)->forSelection(new AudienceQuery());

        $this->assertSame(2, $zahlen['buyers']);
        $this->assertSame(4, $zahlen['tickets']);
        $this->assertSame(3, $zahlen['orders']);
        $this->assertSame(115.0, $zahlen['revenue']);
    }

    public function test_tickets_without_a_buyer_count_as_tickets_and_not_as_people(): void
    {
        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3]]);
        $this->pretixOrder('sommerfest', 'LEER1', null, [['item' => 3]]);

        $zahlen = app(AudienceStats::class)->forSelection(new AudienceQuery());

        $this->assertSame(1, $zahlen['buyers']);
        $this->assertSame(2, $zahlen['tickets']);
        $this->assertSame(1, $zahlen['tickets_without_buyer']);
    }

    public function test_it_counts_buyers_who_appear_at_more_than_one_event(): void
    {
        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3]]);
        $this->pretixOrder('winterball', 'BBBBB', 'a@example.test', [['item' => 7]]);
        $this->pretixOrder('winterball', 'CCCCC', 'b@example.test', [['item' => 7]]);

        $zahlen = app(AudienceStats::class)->forSelection(new AudienceQuery());

        $this->assertSame(1, $zahlen['multi_event_buyers']);
        $this->assertSame(50.0, $zahlen['returning_share']);
    }

    public function test_the_same_order_code_at_two_events_counts_twice(): void
    {
        $this->pretixOrder('sommerfest', 'GLEICH', 'a@example.test', [['item' => 3]]);
        $this->pretixOrder('winterball', 'GLEICH', 'b@example.test', [['item' => 7]]);

        $this->assertSame(2, app(AudienceStats::class)->forSelection(new AudienceQuery())['orders']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AudienceStatsTest`
Expected: FAIL mit „Class App\Services\Audience\AudienceStats does not exist".

- [ ] **Step 3: Write the implementation**

Create `app/Services/Audience/AudienceStats.php`:

```php
<?php

namespace App\Services\Audience;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The headline figures of a selection.
 */
class AudienceStats
{
    /**
     * @return array{buyers: int, tickets: int, orders: int, revenue: float, tickets_without_buyer: int, multi_event_buyers: int, returning_share: float}
     */
    public function forSelection(AudienceQuery $query): array
    {
        $kaeufer = (int) $query->buyers()->distinct()->count('buyer_email');
        $mehrfach = $this->multiEventBuyers($query);

        return [
            'buyers' => $kaeufer,
            'tickets' => (int) $query->positions()->count(),
            'orders' => $this->countOrders($query->positions()),
            'revenue' => (float) $query->positions()->sum('price'),
            'tickets_without_buyer' => (int) $query->positions()->whereNull('buyer_email')->count(),
            'multi_event_buyers' => $mehrfach,
            'returning_share' => $kaeufer > 0 ? round($mehrfach * 100 / $kaeufer, 1) : 0.0,
        ];
    }

    /**
     * Distinct orders behind a position query.
     *
     * OVER slug + code, because a pretix order code is unique within its event and
     * two events may well both have an order "GLEICH". `||` is the string
     * concatenation of SQLite and PostgreSQL alike.
     */
    private function countOrders(Builder $positions): int
    {
        return (int) $positions
            ->selectRaw("COUNT(DISTINCT event_slug || '/' || order_code) as anzahl")
            ->value('anzahl');
    }

    /** Buyers with tickets at more than one of the selected events. */
    private function multiEventBuyers(AudienceQuery $query): int
    {
        $gruppen = $query->buyers()
            ->select('buyer_email')
            ->groupBy('buyer_email')
            ->havingRaw('COUNT(DISTINCT event_slug) > 1');

        return (int) DB::query()->fromSub($gruppen, 'mehrfach')->count();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AudienceStatsTest`
Expected: PASS, 4 Tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Audience/AudienceStats.php tests/Feature/Audience/AudienceStatsTest.php
git commit -m "Kopfzahlen der Publikumsauswertung"
```

---

### Task 6: Überschneidung und Erstkäufer

**Files:**
- Create: `app/Services/Audience/AudienceOverlap.php`
- Test: `tests/Feature/Audience/AudienceOverlapTest.php`

**Interfaces:**
- Consumes: `AudienceQuery` aus Task 4
- Produces:
  - `AudienceOverlap::matrix(AudienceQuery $query): array{events: array<int, string>, rows: array<string, array{buyers:int, shared: array<string, array{count:int, share:float}>}>}`
  - `AudienceOverlap::firstTimeByEvent(AudienceQuery $query): array<string, array{first_time:int, returning:int, share:float}>`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Audience/AudienceOverlapTest.php`:

```php
<?php

namespace Tests\Feature\Audience;

use App\Models\User;
use App\Services\Audience\AudienceOverlap;
use App\Services\Audience\AudienceQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

/**
 * Who of A was also at B.
 *
 * THE PERCENTAGE IS ASYMMETRIC on purpose: "3 of 4 buyers of A were also at B" and
 * "3 of 30 buyers of B were also at A" are different sentences, and only the first
 * one answers whether A's audience carries over.
 */
class AudienceOverlapTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);

        // anna und bodo waren bei beiden, clara nur beim Sommerfest,
        // dora und emil nur beim Winterball.
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]], orderedAt: '2026-03-01 10:00:00');
        $this->pretixOrder('sommerfest', 'S2', 'bodo@example.test', [['item' => 3]], orderedAt: '2026-03-02 10:00:00');
        $this->pretixOrder('sommerfest', 'S3', 'clara@example.test', [['item' => 3]], orderedAt: '2026-03-03 10:00:00');
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7]], orderedAt: '2026-08-01 10:00:00');
        $this->pretixOrder('winterball', 'W2', 'bodo@example.test', [['item' => 7]], orderedAt: '2026-08-02 10:00:00');
        $this->pretixOrder('winterball', 'W3', 'dora@example.test', [['item' => 7]], orderedAt: '2026-08-03 10:00:00');
        $this->pretixOrder('winterball', 'W4', 'emil@example.test', [['item' => 7]], orderedAt: '2026-08-04 10:00:00');
    }

    public function test_the_matrix_counts_shared_buyers_per_pair(): void
    {
        $matrix = app(AudienceOverlap::class)->matrix(new AudienceQuery());

        $this->assertSame(['sommerfest', 'winterball'], $matrix['events']);
        $this->assertSame(3, $matrix['rows']['sommerfest']['buyers']);
        $this->assertSame(4, $matrix['rows']['winterball']['buyers']);
        $this->assertSame(2, $matrix['rows']['sommerfest']['shared']['winterball']['count']);
        $this->assertSame(2, $matrix['rows']['winterball']['shared']['sommerfest']['count']);
    }

    public function test_the_share_is_measured_against_the_row_event(): void
    {
        $matrix = app(AudienceOverlap::class)->matrix(new AudienceQuery());

        $this->assertSame(66.7, $matrix['rows']['sommerfest']['shared']['winterball']['share']);
        $this->assertSame(50.0, $matrix['rows']['winterball']['shared']['sommerfest']['share']);
    }

    public function test_an_event_shares_all_of_its_buyers_with_itself(): void
    {
        $matrix = app(AudienceOverlap::class)->matrix(new AudienceQuery());

        $this->assertSame(3, $matrix['rows']['sommerfest']['shared']['sommerfest']['count']);
        $this->assertSame(100.0, $matrix['rows']['sommerfest']['shared']['sommerfest']['share']);
    }

    public function test_first_time_buyers_are_measured_against_the_whole_visible_stock(): void
    {
        $zahlen = app(AudienceOverlap::class)->firstTimeByEvent(new AudienceQuery());

        // Alle drei Sommerfest-Kaeufer kaufen dort zuerst.
        $this->assertSame(3, $zahlen['sommerfest']['first_time']);
        $this->assertSame(0, $zahlen['sommerfest']['returning']);

        // Beim Winterball sind anna und bodo wiederkehrend, dora und emil neu.
        $this->assertSame(2, $zahlen['winterball']['first_time']);
        $this->assertSame(2, $zahlen['winterball']['returning']);
        $this->assertSame(50.0, $zahlen['winterball']['share']);
    }

    public function test_narrowing_the_selection_leaves_the_first_time_verdict_alone(): void
    {
        $zahlen = app(AudienceOverlap::class)->firstTimeByEvent(new AudienceQuery(eventSlugs: ['winterball']));

        // anna und bodo bleiben wiederkehrend, obwohl das Sommerfest nicht gewaehlt ist.
        $this->assertSame(2, $zahlen['winterball']['returning']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AudienceOverlapTest`
Expected: FAIL mit „Class App\Services\Audience\AudienceOverlap does not exist".

- [ ] **Step 3: Write the implementation**

Create `app/Services/Audience/AudienceOverlap.php`:

```php
<?php

namespace App\Services\Audience;

/**
 * Which events share an audience.
 *
 * BUILT IN PHP from a distinct list of (buyer, event) pairs rather than as a
 * self-join per pair: the pair count grows with the square of the events, and at
 * this size the whole list is a few thousand rows. One query, portable across
 * SQLite and PostgreSQL, and the intersection logic stays readable.
 */
class AudienceOverlap
{
    /**
     * @return array{events: array<int, string>, rows: array<string, array{buyers: int, shared: array<string, array{count: int, share: float}>}>}
     */
    public function matrix(AudienceQuery $query): array
    {
        $proEvent = $this->buyersByEvent($query);
        $events = array_keys($proEvent);
        sort($events);

        $rows = [];

        foreach ($events as $a) {
            $anzahlA = count($proEvent[$a]);
            $geteilt = [];

            foreach ($events as $b) {
                $gemeinsam = count(array_intersect_key($proEvent[$a], $proEvent[$b]));

                $geteilt[$b] = [
                    'count' => $gemeinsam,
                    'share' => $anzahlA > 0 ? round($gemeinsam * 100 / $anzahlA, 1) : 0.0,
                ];
            }

            $rows[$a] = ['buyers' => $anzahlA, 'shared' => $geteilt];
        }

        return ['events' => $events, 'rows' => $rows];
    }

    /**
     * New against returning buyers per event.
     *
     * A buyer counts as FIRST TIME at the event their earliest purchase in the whole
     * VISIBLE stock belongs to. Measured against the visible stock rather than the
     * current selection, so the verdict does not flip when someone unticks an event.
     *
     * @return array<string, array{first_time: int, returning: int, share: float}>
     */
    public function firstTimeByEvent(AudienceQuery $query): array
    {
        $erstes = $this->firstEventPerBuyer($query);
        $proEvent = $this->buyersByEvent($query);
        $zahlen = [];

        foreach ($proEvent as $slug => $kaeufer) {
            $neu = 0;

            foreach (array_keys($kaeufer) as $mail) {
                if (($erstes[$mail] ?? null) === $slug) {
                    $neu++;
                }
            }

            $gesamt = count($kaeufer);

            $zahlen[$slug] = [
                'first_time' => $neu,
                'returning' => $gesamt - $neu,
                'share' => $gesamt > 0 ? round(($gesamt - $neu) * 100 / $gesamt, 1) : 0.0,
            ];
        }

        ksort($zahlen);

        return $zahlen;
    }

    /**
     * The buyers of each selected event, as slug => [email => true].
     *
     * @return array<string, array<string, true>>
     */
    private function buyersByEvent(AudienceQuery $query): array
    {
        $proEvent = [];

        $paare = $query->buyers()
            ->select('event_slug', 'buyer_email')
            ->distinct()
            ->get();

        foreach ($paare as $paar) {
            $proEvent[$paar->event_slug][$paar->buyer_email] = true;
        }

        ksort($proEvent);

        return $proEvent;
    }

    /**
     * For every buyer, the event of their earliest purchase in the visible stock.
     *
     * Ties - the same timestamp at two events - go to the alphabetically first slug,
     * so the figure does not depend on the order rows come back in.
     *
     * @return array<string, string>
     */
    private function firstEventPerBuyer(AudienceQuery $query): array
    {
        $zeilen = $query->visiblePositions()
            ->whereNotNull('buyer_email')
            ->selectRaw('buyer_email, event_slug, MIN(ordered_at) as frueheste')
            ->groupBy('buyer_email', 'event_slug')
            ->get();

        $bestes = [];

        foreach ($zeilen as $zeile) {
            $mail = $zeile->buyer_email;
            $zeitpunkt = (string) $zeile->frueheste;
            $vorhanden = $bestes[$mail] ?? null;

            if ($vorhanden === null
                || $zeitpunkt < $vorhanden['at']
                || ($zeitpunkt === $vorhanden['at'] && $zeile->event_slug < $vorhanden['slug'])) {
                $bestes[$mail] = ['at' => $zeitpunkt, 'slug' => $zeile->event_slug];
            }
        }

        return array_map(fn (array $b) => $b['slug'], $bestes);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AudienceOverlapTest`
Expected: PASS, 5 Tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Audience/AudienceOverlap.php tests/Feature/Audience/AudienceOverlapTest.php
git commit -m "Ueberschneidungsmatrix und Erstkaeufer je Veranstaltung"
```

---

### Task 7: Käuferliste

**Files:**
- Create: `app/Services/Audience/AudienceBuyers.php`
- Modify: `app/Models/Event.php` (neue statische Methode `namesBySlug`)
- Test: `tests/Feature/Audience/AudienceBuyersTest.php`

**Interfaces:**
- Consumes: `AudienceQuery` aus Task 4
- Produces:
  - `App\Models\Event::namesBySlug(): array<string, string>` — pretix-Slug auf Eventnamen, bei mehrfach vergebenem Slug der zuerst angelegte Datensatz
  - `AudienceBuyers::query(AudienceQuery $query): Builder` mit den Auswahlfeldern `id`, `buyer_email`, `buyer_display_name`, `tickets`, `revenue`, `events`, `orders`, `first_at`, `last_at`
  - `AudienceBuyers::search(Builder $query, string $term): Builder`
  - `AudienceBuyers::eventNames(AudienceQuery $query, string $email): array<int, string>`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Audience/AudienceBuyersTest.php`:

```php
<?php

namespace Tests\Feature\Audience;

use App\Models\User;
use App\Services\Audience\AudienceBuyers;
use App\Services\Audience\AudienceQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

class AudienceBuyersTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);
    }

    public function test_one_row_per_buyer_with_their_totals(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [
            ['item' => 3, 'price' => 40.0],
            ['item' => 4, 'price' => 10.0],
        ], orderedAt: '2026-03-01 10:00:00');

        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [
            ['item' => 7, 'price' => 25.0],
        ], orderedAt: '2026-08-01 10:00:00');

        $this->pretixOrder('winterball', 'W2', 'bodo@example.test', [['item' => 7, 'price' => 25.0]]);

        $zeilen = app(AudienceBuyers::class)->query(new AudienceQuery())->get()->keyBy('buyer_email');

        $this->assertCount(2, $zeilen);

        $anna = $zeilen['anna@example.test'];
        $this->assertSame(3, (int) $anna->tickets);
        $this->assertSame(2, (int) $anna->orders);
        $this->assertSame(2, (int) $anna->events);
        $this->assertSame(75.0, (float) $anna->revenue);
        $this->assertStringStartsWith('2026-03-01', (string) $anna->first_at);
        $this->assertStringStartsWith('2026-08-01', (string) $anna->last_at);
    }

    public function test_a_buyer_without_an_address_is_left_out(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]]);
        $this->pretixOrder('sommerfest', 'LEER1', null, [['item' => 3]]);

        $this->assertCount(1, app(AudienceBuyers::class)->query(new AudienceQuery())->get());
    }

    public function test_the_display_name_is_one_of_the_names_on_that_address(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]], address: ['name' => 'Anna Beispiel']);

        $zeile = app(AudienceBuyers::class)->query(new AudienceQuery())->first();

        $this->assertSame('Anna Beispiel', $zeile->buyer_display_name);
    }

    public function test_the_event_names_of_one_buyer_come_back_readable(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]]);
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7]]);

        \App\Models\Event::create(['name' => 'Sommerfest 2026', 'pretix_event_slug' => 'sommerfest', 'is_active' => true]);

        $namen = app(AudienceBuyers::class)->eventNames(new AudienceQuery(), 'anna@example.test');

        // Der Slug ohne Event-Datensatz bleibt als Slug stehen, statt zu verschwinden.
        $this->assertSame(['Sommerfest 2026', 'winterball'], $namen);
    }

    public function test_the_list_can_be_searched_case_insensitively(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'Anna.Gross@Example.test', [['item' => 3]], address: ['name' => 'Anna Gross']);
        $this->pretixOrder('sommerfest', 'S2', 'bodo@example.test', [['item' => 3]], address: ['name' => 'Bodo Klein']);

        $treffer = AudienceBuyers::search(
            app(AudienceBuyers::class)->query(new AudienceQuery()),
            'ANNA',
        )->get();

        $this->assertCount(1, $treffer);
        $this->assertSame('anna.gross@example.test', $treffer->first()->buyer_email);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AudienceBuyersTest`
Expected: FAIL mit „Class App\Services\Audience\AudienceBuyers does not exist".

- [ ] **Step 3: Add the slug-to-name map to the Event model**

In `app/Models/Event.php`, nach `displayName()` einfügen:

```php
    /**
     * pretix slug => event name, for every event that has a slug.
     *
     * TWO EVENTS MAY CARRY THE SAME SLUG - the audience groups by slug, so one name
     * has to win, and it is the one that was set up first. A plain pluck() would let
     * whichever row came back last win, which changes with the sort order.
     *
     * @return array<string, string>
     */
    public static function namesBySlug(): array
    {
        $namen = [];

        foreach (static::query()->whereNotNull('pretix_event_slug')->orderBy('id')->get(['id', 'name', 'pretix_event_slug']) as $event) {
            $namen[$event->pretix_event_slug] ??= (string) $event->name;
        }

        return $namen;
    }
```

- [ ] **Step 4: Write the implementation**

Create `app/Services/Audience/AudienceBuyers.php`:

```php
<?php

namespace App\Services\Audience;

use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;

/**
 * The buyer list: one row per e-mail address, with their totals.
 *
 * RETURNS A BUILDER, not an array, so the Filament table can sort, search and
 * paginate in the database. `MIN(id)` is the record key the table needs - the group
 * itself has none.
 */
class AudienceBuyers
{
    public function query(AudienceQuery $query): Builder
    {
        return $query->buyers()
            ->selectRaw('MIN(id) as id')
            ->selectRaw('buyer_email')
            // One of the names on that address. They are the same in almost every
            // case; where they differ, the per-order names stay visible in the
            // participant export.
            ->selectRaw('MAX(buyer_name) as buyer_display_name')
            ->selectRaw('COUNT(*) as tickets')
            ->selectRaw('SUM(price) as revenue')
            ->selectRaw('COUNT(DISTINCT event_slug) as events')
            ->selectRaw("COUNT(DISTINCT event_slug || '/' || order_code) as orders")
            ->selectRaw('MIN(ordered_at) as first_at')
            ->selectRaw('MAX(ordered_at) as last_at')
            ->groupBy('buyer_email');
    }

    /**
     * Narrows the list by e-mail or name.
     *
     * LOWER() ON BOTH SIDES: `LIKE` is case sensitive on PostgreSQL and case
     * insensitive on SQLite, so a plain LIKE passes every test and finds nothing in
     * production. The filter goes into WHERE, before the grouping, which is exactly
     * where it belongs - a buyer matches when any of their rows matches.
     */
    public static function search(Builder $query, string $term): Builder
    {
        $muster = '%' . mb_strtolower(trim($term)) . '%';

        return $query->where(function (Builder $query) use ($muster) {
            $query->whereRaw('LOWER(buyer_email) LIKE ?', [$muster])
                ->orWhereRaw('LOWER(buyer_name) LIKE ?', [$muster]);
        });
    }

    /**
     * The events one buyer appears at, by name where an event is configured.
     *
     * A slug without an Event record stays visible as the slug: an order for an
     * event nobody set up yet is still a purchase, and dropping it would make the
     * count in the list disagree with the names beside it.
     *
     * @return array<int, string>
     */
    public function eventNames(AudienceQuery $query, string $email): array
    {
        $slugs = $query->buyers()
            ->where('buyer_email', $email)
            ->distinct()
            ->orderBy('event_slug')
            ->pluck('event_slug')
            ->all();

        $namen = Event::namesBySlug();

        return array_map(fn (string $slug) => $namen[$slug] ?? $slug, $slugs);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AudienceBuyersTest`
Expected: PASS, 5 Tests.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Event.php app/Services/Audience/AudienceBuyers.php tests/Feature/Audience/AudienceBuyersTest.php
git commit -m "Kaeuferliste als Abfrage fuer die Tabelle"
```

---

### Task 8: Dimensionen I — Ticketarten, Vorlaufzeit, Gruppengröße

**Files:**
- Create: `app/Services/Audience/AudienceDimensions.php`
- Test: `tests/Feature/Audience/AudienceDimensionsTest.php`

**Interfaces:**
- Consumes: `AudienceQuery` aus Task 4
- Produces:
  - `AudienceDimensions::ticketTypes(AudienceQuery $query): array<int, array{event:string, item_id:int, name:string, tickets:int, revenue:float}>`
  - `AudienceDimensions::sameTypeAcrossEvents(AudienceQuery $query): array{buyers:int, loyal:int, share:float}`
  - `AudienceDimensions::leadTime(AudienceQuery $query): array{classes: array<string, int>, covered:int, uncovered:int}`
  - `AudienceDimensions::groupSize(AudienceQuery $query): array{classes: array<string, int>, average: float, with_other_attendee:int, comparable:int}`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Audience/AudienceDimensionsTest.php`:

```php
<?php

namespace Tests\Feature\Audience;

use App\Models\Event;
use App\Models\User;
use App\Services\Audience\AudienceDimensions;
use App\Services\Audience\AudienceQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

class AudienceDimensionsTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);

        $this->ticketType('sommerfest', 3, 'VIP');
        $this->ticketType('sommerfest', 4, 'Normal');
        $this->ticketType('winterball', 7, 'VIP');
    }

    public function test_ticket_types_are_counted_per_event_with_their_names(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [
            ['item' => 3, 'price' => 40.0],
            ['item' => 4, 'price' => 10.0],
            ['item' => 4, 'price' => 10.0],
        ]);

        $arten = app(AudienceDimensions::class)->ticketTypes(new AudienceQuery());

        $this->assertSame('Normal', $arten[0]['name']);
        $this->assertSame(2, $arten[0]['tickets']);
        $this->assertSame(20.0, $arten[0]['revenue']);
        $this->assertSame('VIP', $arten[1]['name']);
        $this->assertSame(1, $arten[1]['tickets']);
    }

    public function test_an_unknown_ticket_type_keeps_its_number(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 99]]);

        $arten = app(AudienceDimensions::class)->ticketTypes(new AudienceQuery());

        $this->assertSame('Ticketart #99', $arten[0]['name']);
    }

    public function test_loyalty_to_a_ticket_type_compares_names_and_not_numbers(): void
    {
        // anna kauft bei beiden Events "VIP" - unter verschiedenen Nummern.
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]]);
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7]]);

        // bodo wechselt von Normal zu VIP.
        $this->pretixOrder('sommerfest', 'S2', 'bodo@example.test', [['item' => 4]]);
        $this->pretixOrder('winterball', 'W2', 'bodo@example.test', [['item' => 7]]);

        $treue = app(AudienceDimensions::class)->sameTypeAcrossEvents(new AudienceQuery());

        $this->assertSame(2, $treue['buyers']);
        $this->assertSame(1, $treue['loyal']);
        $this->assertSame(50.0, $treue['share']);
    }

    public function test_lead_time_uses_the_event_date_and_reports_its_coverage(): void
    {
        Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'event_date' => '2026-07-10', 'is_active' => true]);

        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]], orderedAt: '2026-07-10 08:00:00');
        $this->pretixOrder('sommerfest', 'S2', 'bodo@example.test', [['item' => 3]], orderedAt: '2026-07-08 08:00:00');
        $this->pretixOrder('sommerfest', 'S3', 'clara@example.test', [['item' => 3]], orderedAt: '2026-01-10 08:00:00');
        // Ohne Event-Datensatz, faellt aus der Quote heraus.
        $this->pretixOrder('winterball', 'W1', 'dora@example.test', [['item' => 7]], orderedAt: '2026-07-01 08:00:00');

        $vorlauf = app(AudienceDimensions::class)->leadTime(new AudienceQuery());

        $this->assertSame(1, $vorlauf['classes']['am Tag selbst']);
        $this->assertSame(1, $vorlauf['classes']['1 bis 3 Tage']);
        $this->assertSame(1, $vorlauf['classes']['über 90 Tage']);
        $this->assertSame(3, $vorlauf['covered']);
        $this->assertSame(1, $vorlauf['uncovered']);
    }

    public function test_group_size_counts_tickets_per_order(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]]);
        $this->pretixOrder('sommerfest', 'S2', 'bodo@example.test', [['item' => 3], ['item' => 4]]);
        $this->pretixOrder('sommerfest', 'S3', 'clara@example.test', [['item' => 3], ['item' => 4], ['item' => 4]]);

        $gruppen = app(AudienceDimensions::class)->groupSize(new AudienceQuery());

        $this->assertSame(1, $gruppen['classes']['1']);
        $this->assertSame(1, $gruppen['classes']['2']);
        $this->assertSame(1, $gruppen['classes']['3']);
        $this->assertSame(2.0, $gruppen['average']);
    }

    public function test_a_differing_name_on_the_ticket_counts_as_company(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [
            ['item' => 3, 'attendee' => 'Anna Beispiel'],
            ['item' => 4, 'attendee' => 'Lena Anders'],
        ], address: ['name' => 'Anna Beispiel']);

        $gruppen = app(AudienceDimensions::class)->groupSize(new AudienceQuery());

        $this->assertSame(2, $gruppen['comparable']);
        $this->assertSame(1, $gruppen['with_other_attendee']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AudienceDimensionsTest`
Expected: FAIL mit „Class App\Services\Audience\AudienceDimensions does not exist".

- [ ] **Step 3: Write the implementation**

Create `app/Services/Audience/AudienceDimensions.php`:

```php
<?php

namespace App\Services\Audience;

use App\Models\Event;
use App\Models\PretixItem;
use Illuminate\Support\Carbon;

/**
 * The patterns beyond the headline figures.
 *
 * Everything here is aggregated in SQL and shaped in PHP. At this size - a few
 * thousand rows - that stays fast, and the classification rules stay readable
 * instead of hiding in a CASE expression per database.
 */
class AudienceDimensions
{
    /**
     * Tickets and revenue per ticket type, with the name behind the number.
     *
     * @return array<int, array{event: string, item_id: int, name: string, tickets: int, revenue: float}>
     */
    public function ticketTypes(AudienceQuery $query): array
    {
        $zeilen = $query->positions()
            ->selectRaw('event_slug, item_id, COUNT(*) as tickets, SUM(price) as revenue')
            ->groupBy('event_slug', 'item_id')
            ->get();

        $namen = $this->itemNames();

        $arten = $zeilen->map(fn ($zeile) => [
            'event' => $zeile->event_slug,
            'item_id' => (int) $zeile->item_id,
            'name' => $namen[$zeile->event_slug][(int) $zeile->item_id] ?? ('Ticketart #' . (int) $zeile->item_id),
            'tickets' => (int) $zeile->tickets,
            'revenue' => (float) $zeile->revenue,
        ])->all();

        usort($arten, fn (array $a, array $b) => [$a['event'], $a['name']] <=> [$b['event'], $b['name']]);

        return $arten;
    }

    /**
     * Buyers who pick the same kind of ticket at every event they attend.
     *
     * COMPARED BY NAME, never by item_id: every event has its own ticket type
     * numbers, so the same "VIP" is a different number at each of them and an id
     * comparison would find loyalty nowhere.
     *
     * @return array{buyers: int, loyal: int, share: float}
     */
    public function sameTypeAcrossEvents(AudienceQuery $query): array
    {
        $zeilen = $query->buyers()
            ->select('buyer_email', 'event_slug', 'item_id')
            ->distinct()
            ->get();

        $namen = $this->itemNames();
        $proKaeufer = [];

        foreach ($zeilen as $zeile) {
            $name = $namen[$zeile->event_slug][(int) $zeile->item_id] ?? ('#' . (int) $zeile->item_id);
            $proKaeufer[$zeile->buyer_email]['events'][$zeile->event_slug] = true;
            $proKaeufer[$zeile->buyer_email]['types'][mb_strtolower(trim($name))] = true;
        }

        $mehrfach = 0;
        $treu = 0;

        foreach ($proKaeufer as $kaeufer) {
            if (count($kaeufer['events']) < 2) {
                continue;
            }

            $mehrfach++;

            if (count($kaeufer['types']) === 1) {
                $treu++;
            }
        }

        return [
            'buyers' => $mehrfach,
            'loyal' => $treu,
            'share' => $mehrfach > 0 ? round($treu * 100 / $mehrfach, 1) : 0.0,
        ];
    }

    /**
     * How long before the event people buy.
     *
     * Needs a maintained event date; tickets for events without one are reported as
     * uncovered rather than dropped silently.
     *
     * @return array{classes: array<string, int>, covered: int, uncovered: int}
     */
    public function leadTime(AudienceQuery $query): array
    {
        $termine = Event::query()
            ->whereNotNull('pretix_event_slug')
            ->whereNotNull('event_date')
            ->pluck('event_date', 'pretix_event_slug');

        $klassen = [
            'am Tag selbst' => 0,
            '1 bis 3 Tage' => 0,
            '4 bis 7 Tage' => 0,
            '8 bis 14 Tage' => 0,
            '15 bis 30 Tage' => 0,
            '31 bis 90 Tage' => 0,
            'über 90 Tage' => 0,
        ];

        $erfasst = 0;
        $offen = 0;

        foreach ($query->positions()->select('event_slug', 'ordered_at')->get() as $zeile) {
            $termin = $termine[$zeile->event_slug] ?? null;

            if ($termin === null || $zeile->ordered_at === null) {
                $offen++;

                continue;
            }

            $erfasst++;
            $tage = Carbon::parse($zeile->ordered_at)->startOfDay()->diffInDays(Carbon::parse($termin)->startOfDay(), false);
            $klassen[$this->leadTimeClass((int) $tage)]++;
        }

        return ['classes' => $klassen, 'covered' => $erfasst, 'uncovered' => $offen];
    }

    /**
     * Tickets per order, and how often someone books for other people.
     *
     * @return array{classes: array<string, int>, average: float, with_other_attendee: int, comparable: int}
     */
    public function groupSize(AudienceQuery $query): array
    {
        $bestellungen = $query->positions()
            ->selectRaw("event_slug || '/' || order_code as bestellung, COUNT(*) as tickets")
            ->groupBy('event_slug', 'order_code')
            ->get();

        $klassen = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5 bis 9' => 0, 'ab 10' => 0];
        $summe = 0;

        foreach ($bestellungen as $bestellung) {
            $anzahl = (int) $bestellung->tickets;
            $summe += $anzahl;
            $klassen[$this->groupSizeClass($anzahl)]++;
        }

        $mitNamen = $query->positions()
            ->whereNotNull('attendee_name')
            ->whereNotNull('buyer_name')
            ->select('attendee_name', 'buyer_name')
            ->get();

        $abweichend = $mitNamen->filter(
            fn ($zeile) => mb_strtolower(trim($zeile->attendee_name)) !== mb_strtolower(trim($zeile->buyer_name)),
        )->count();

        return [
            'classes' => $klassen,
            'average' => $bestellungen->count() > 0 ? round($summe / $bestellungen->count(), 2) : 0.0,
            'with_other_attendee' => $abweichend,
            'comparable' => $mitNamen->count(),
        ];
    }

    private function leadTimeClass(int $tage): string
    {
        return match (true) {
            $tage <= 0 => 'am Tag selbst',
            $tage <= 3 => '1 bis 3 Tage',
            $tage <= 7 => '4 bis 7 Tage',
            $tage <= 14 => '8 bis 14 Tage',
            $tage <= 30 => '15 bis 30 Tage',
            $tage <= 90 => '31 bis 90 Tage',
            default => 'über 90 Tage',
        };
    }

    private function groupSizeClass(int $anzahl): string
    {
        return match (true) {
            $anzahl <= 1 => '1',
            $anzahl === 2 => '2',
            $anzahl === 3 => '3',
            $anzahl === 4 => '4',
            $anzahl <= 9 => '5 bis 9',
            default => 'ab 10',
        };
    }

    /**
     * Ticket type names as slug => [item_id => name].
     *
     * @return array<string, array<int, string>>
     */
    private function itemNames(): array
    {
        $namen = [];

        foreach (PretixItem::query()->get(['event_slug', 'item_id', 'name']) as $art) {
            $namen[$art->event_slug][(int) $art->item_id] = (string) $art->name;
        }

        return $namen;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AudienceDimensionsTest`
Expected: PASS, 6 Tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Audience/AudienceDimensions.php tests/Feature/Audience/AudienceDimensionsTest.php
git commit -m "Dimensionen: Ticketarten, Vorlaufzeit, Gruppengroesse"
```

---

### Task 9: Dimensionen II — Verlauf, Wert, Gutschein, Zahlungsart, Herkunft, Storno, Fragen

**Files:**
- Modify: `app/Services/Audience/AudienceDimensions.php`
- Test: `tests/Feature/Audience/AudienceDimensionsMoreTest.php`

**Interfaces:**
- Consumes: `AudienceQuery` aus Task 4, `AudienceDimensions` aus Task 8
- Produces:
  - `AudienceDimensions::salesCurve(AudienceQuery $query): array{by_day: array<string, int>, by_days_before: array<int, int>, by_weekday: array<string, int>, by_hour_block: array<string, int>, without_event_date: int}`
  - `AudienceDimensions::orderValue(AudienceQuery $query): array{classes: array<string, int>, average: float, median: float}`
  - `AudienceDimensions::vouchers(AudienceQuery $query): array{with:int, total:int, share:float, top: array<int, array{voucher:string, tickets:int}>}`
  - `AudienceDimensions::paymentProviders(AudienceQuery $query): array<string, int>`
  - `AudienceDimensions::origin(AudienceQuery $query): array{regions: array<int, array{region:string, tickets:int}>, cities: array<int, array{city:string, tickets:int}>, countries: array<string, int>, covered:int, total:int, coverage:float}`
  - `AudienceDimensions::cancellations(AudienceQuery $query): array{by_event: array<string, array{cancelled:int, total:int, share:float}>, buyers_only_cancelled:int}`
  - `AudienceDimensions::questions(AudienceQuery $query): array<string, array{question:string, answers: array<string, int>}>`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Audience/AudienceDimensionsMoreTest.php`:

```php
<?php

namespace Tests\Feature\Audience;

use App\Models\User;
use App\Services\Audience\AudienceDimensions;
use App\Services\Audience\AudienceQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

class AudienceDimensionsMoreTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);
    }

    public function test_the_sales_curve_counts_orders_per_day_weekday_and_time_of_day(): void
    {
        // 01.07.2026 ist ein Mittwoch, 04.07.2026 ein Samstag.
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3]], orderedAt: '2026-07-01 09:30:00');
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3]], orderedAt: '2026-07-01 21:00:00');
        $this->pretixOrder('sommerfest', 'S3', 'c@example.test', [['item' => 3]], orderedAt: '2026-07-04 13:00:00');

        $verlauf = app(AudienceDimensions::class)->salesCurve(new AudienceQuery());

        $this->assertSame(2, $verlauf['by_day']['2026-07-01']);
        $this->assertSame(1, $verlauf['by_day']['2026-07-04']);
        $this->assertSame(2, $verlauf['by_weekday']['Mittwoch']);
        $this->assertSame(1, $verlauf['by_weekday']['Samstag']);
        $this->assertSame(1, $verlauf['by_hour_block']['08–11 Uhr']);
        $this->assertSame(1, $verlauf['by_hour_block']['20–23 Uhr']);
    }

    public function test_the_second_axis_counts_days_before_the_event(): void
    {
        \App\Models\Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'event_date' => '2026-07-10', 'is_active' => true]);

        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3]], orderedAt: '2026-07-09 09:00:00');
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3]], orderedAt: '2026-07-09 18:00:00');
        $this->pretixOrder('sommerfest', 'S3', 'c@example.test', [['item' => 3]], orderedAt: '2026-06-10 09:00:00');
        // Ohne Event-Datensatz: zaehlt im Kalender mit, in der zweiten Achse nicht.
        $this->pretixOrder('winterball', 'W1', 'd@example.test', [['item' => 7]], orderedAt: '2026-07-01 09:00:00');

        $verlauf = app(AudienceDimensions::class)->salesCurve(new AudienceQuery());

        $this->assertSame(2, $verlauf['by_days_before'][1]);
        $this->assertSame(1, $verlauf['by_days_before'][30]);
        $this->assertSame(1, $verlauf['without_event_date']);
        $this->assertArrayNotHasKey(0, $verlauf['by_days_before']);
    }

    public function test_order_value_reports_median_and_average(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3, 'price' => 10.0]]);
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3, 'price' => 20.0]]);
        $this->pretixOrder('sommerfest', 'S3', 'c@example.test', [['item' => 3, 'price' => 120.0]]);

        $werte = app(AudienceDimensions::class)->orderValue(new AudienceQuery());

        $this->assertSame(20.0, $werte['median']);
        $this->assertSame(50.0, $werte['average']);
        $this->assertSame(1, $werte['classes']['über 100 €']);
    }

    public function test_vouchers_are_counted_and_the_most_used_ones_listed(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3, 'voucher' => '55'], ['item' => 4]]);
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3, 'voucher' => '55']]);

        $gutscheine = app(AudienceDimensions::class)->vouchers(new AudienceQuery());

        $this->assertSame(2, $gutscheine['with']);
        $this->assertSame(3, $gutscheine['total']);
        $this->assertSame(66.7, $gutscheine['share']);
        $this->assertSame('55', $gutscheine['top'][0]['voucher']);
        $this->assertSame(2, $gutscheine['top'][0]['tickets']);
    }

    public function test_payment_providers_are_counted_per_order(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3], ['item' => 4]], provider: 'paypal');
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3]], provider: 'banktransfer');

        $arten = app(AudienceDimensions::class)->paymentProviders(new AudienceQuery());

        $this->assertSame(1, $arten['paypal']);
        $this->assertSame(1, $arten['banktransfer']);
    }

    public function test_origin_reports_regions_and_its_own_coverage(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3]], address: ['zipcode' => '23966', 'city' => 'Wismar', 'country' => 'DE']);
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3]], address: ['zipcode' => '23970', 'city' => 'Wismar', 'country' => 'DE']);
        $this->pretixOrder('sommerfest', 'S3', 'c@example.test', [['item' => 3]], address: []);

        $herkunft = app(AudienceDimensions::class)->origin(new AudienceQuery());

        $this->assertSame('23', $herkunft['regions'][0]['region']);
        $this->assertSame(2, $herkunft['regions'][0]['tickets']);
        $this->assertSame('Wismar', $herkunft['cities'][0]['city']);
        $this->assertSame(2, $herkunft['covered']);
        $this->assertSame(3, $herkunft['total']);
        $this->assertSame(66.7, $herkunft['coverage']);
    }

    public function test_cancellations_are_counted_although_the_status_filter_says_paid(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3]]);
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3]], status: 'c');
        $this->pretixOrder('sommerfest', 'S3', 'c@example.test', [['item' => 3]], status: 'e');

        $storno = app(AudienceDimensions::class)->cancellations(new AudienceQuery());

        $this->assertSame(2, $storno['by_event']['sommerfest']['cancelled']);
        $this->assertSame(3, $storno['by_event']['sommerfest']['total']);
        $this->assertSame(66.7, $storno['by_event']['sommerfest']['share']);
        $this->assertSame(2, $storno['buyers_only_cancelled']);
    }

    public function test_position_questions_are_reported_generically(): void
    {
        $order = $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3]]);
        $payload = $order->raw_payload;
        $payload['positions'][0]['answers'] = [[
            'question' => 17,
            'question_identifier' => 'SHUTTLE',
            'answer' => 'True',
        ]];
        $order->update(['raw_payload' => $payload]);

        $fragen = app(AudienceDimensions::class)->questions(new AudienceQuery());

        $this->assertSame('SHUTTLE', $fragen['SHUTTLE']['question']);
        $this->assertSame(1, $fragen['SHUTTLE']['answers']['True']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AudienceDimensionsMoreTest`
Expected: FAIL mit „Call to undefined method App\Services\Audience\AudienceDimensions::salesCurve()".

- [ ] **Step 3: Add the methods**

In `app/Services/Audience/AudienceDimensions.php`, vor `leadTimeClass()` einfügen:

```php
    /**
     * When people buy: per calendar day, per day before the event, per weekday, per
     * four-hour block.
     *
     * THE SECOND AXIS EARNS ITS PLACE: on a calendar the curves of two events sit
     * side by side and say nothing about each other. Counted in days before the
     * event they lie on top of each other, and that is where "this one sold late"
     * becomes visible. It needs a maintained event date, so orders without one are
     * reported rather than dropped.
     *
     * @return array{by_day: array<string, int>, by_days_before: array<int, int>, by_weekday: array<string, int>, by_hour_block: array<string, int>, without_event_date: int}
     */
    public function salesCurve(AudienceQuery $query): array
    {
        $wochentage = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        $bloecke = ['00–03 Uhr', '04–07 Uhr', '08–11 Uhr', '12–15 Uhr', '16–19 Uhr', '20–23 Uhr'];

        $termine = Event::query()
            ->whereNotNull('pretix_event_slug')
            ->whereNotNull('event_date')
            ->pluck('event_date', 'pretix_event_slug');

        $proTag = [];
        $proVorlauf = [];
        $ohneTermin = 0;
        $proWochentag = array_fill_keys($wochentage, 0);
        $proBlock = array_fill_keys($bloecke, 0);

        $bestellungen = $query->positions()
            ->selectRaw('event_slug, order_code, MIN(ordered_at) as bestellt_am')
            ->groupBy('event_slug', 'order_code')
            ->get();

        foreach ($bestellungen as $bestellung) {
            if ($bestellung->bestellt_am === null) {
                continue;
            }

            $zeitpunkt = Carbon::parse($bestellung->bestellt_am);
            $tag = $zeitpunkt->format('Y-m-d');

            $proTag[$tag] = ($proTag[$tag] ?? 0) + 1;
            $proWochentag[$wochentage[$zeitpunkt->dayOfWeekIso - 1]]++;
            $proBlock[$bloecke[intdiv($zeitpunkt->hour, 4)]]++;

            $termin = $termine[$bestellung->event_slug] ?? null;

            if ($termin === null) {
                $ohneTermin++;

                continue;
            }

            $tageVorher = max(0, (int) $zeitpunkt->startOfDay()->diffInDays(Carbon::parse($termin)->startOfDay(), false));
            $proVorlauf[$tageVorher] = ($proVorlauf[$tageVorher] ?? 0) + 1;
        }

        ksort($proTag);
        krsort($proVorlauf);

        return [
            'by_day' => $proTag,
            'by_days_before' => $proVorlauf,
            'by_weekday' => $proWochentag,
            'by_hour_block' => $proBlock,
            'without_event_date' => $ohneTermin,
        ];
    }

    /**
     * What an order is worth.
     *
     * THE DISTRIBUTION COMES FIRST and the median beside the average, because a
     * handful of large orders move an average far enough to describe nobody.
     *
     * @return array{classes: array<string, int>, average: float, median: float}
     */
    public function orderValue(AudienceQuery $query): array
    {
        $summen = $query->positions()
            ->selectRaw('event_slug, order_code, SUM(price) as summe')
            ->groupBy('event_slug', 'order_code')
            ->pluck('summe')
            ->map(fn ($wert) => (float) $wert)
            ->sort()
            ->values()
            ->all();

        $klassen = ['bis 10 €' => 0, '10 bis 25 €' => 0, '25 bis 50 €' => 0, '50 bis 100 €' => 0, 'über 100 €' => 0];

        foreach ($summen as $summe) {
            $klassen[$this->orderValueClass($summe)]++;
        }

        $anzahl = count($summen);

        return [
            'classes' => $klassen,
            'average' => $anzahl > 0 ? round(array_sum($summen) / $anzahl, 2) : 0.0,
            'median' => $anzahl > 0 ? $this->median($summen) : 0.0,
        ];
    }

    /**
     * How much of the audience came in on a voucher.
     *
     * pretix hands over the voucher's IDENTIFIER at the position, not the code that
     * was typed in - the code sits behind its own endpoint. The list therefore groups
     * by that identifier.
     *
     * @return array{with: int, total: int, share: float, top: array<int, array{voucher: string, tickets: int}>}
     */
    public function vouchers(AudienceQuery $query): array
    {
        $gesamt = (int) $query->positions()->count();
        $mit = (int) $query->positions()->whereNotNull('voucher')->count();

        $top = $query->positions()
            ->whereNotNull('voucher')
            ->selectRaw('voucher, COUNT(*) as tickets')
            ->groupBy('voucher')
            ->orderByDesc('tickets')
            ->orderBy('voucher')
            ->limit(15)
            ->get()
            ->map(fn ($zeile) => ['voucher' => (string) $zeile->voucher, 'tickets' => (int) $zeile->tickets])
            ->all();

        return [
            'with' => $mit,
            'total' => $gesamt,
            'share' => $gesamt > 0 ? round($mit * 100 / $gesamt, 1) : 0.0,
            'top' => $top,
        ];
    }

    /**
     * How people paid, counted per order.
     *
     * @return array<string, int>
     */
    public function paymentProviders(AudienceQuery $query): array
    {
        $zeilen = $query->positions()
            ->selectRaw('payment_provider, COUNT(DISTINCT event_slug || \'/\' || order_code) as bestellungen')
            ->groupBy('payment_provider')
            ->orderByDesc('bestellungen')
            ->get();

        $arten = [];

        foreach ($zeilen as $zeile) {
            $arten[(string) ($zeile->payment_provider ?: 'unbekannt')] = (int) $zeile->bestellungen;
        }

        return $arten;
    }

    /**
     * Where the audience comes from - and how much of it we actually know.
     *
     * THE COVERAGE IS PART OF THE ANSWER: the invoice address is filled on well under
     * half the orders, and a regional chart without that number reads as if it
     * described everybody.
     *
     * @return array{regions: array<int, array{region: string, tickets: int}>, cities: array<int, array{city: string, tickets: int}>, countries: array<string, int>, covered: int, total: int, coverage: float}
     */
    public function origin(AudienceQuery $query): array
    {
        $gesamt = (int) $query->positions()->count();
        $erfasst = (int) $query->positions()->whereNotNull('zipcode')->count();

        $regionen = [];

        foreach ($query->positions()->whereNotNull('zipcode')->select('zipcode')->get() as $zeile) {
            $region = mb_substr(preg_replace('/\s+/', '', (string) $zeile->zipcode), 0, 2);

            if ($region === '') {
                continue;
            }

            $regionen[$region] = ($regionen[$region] ?? 0) + 1;
        }

        arsort($regionen);

        $orte = $query->positions()
            ->whereNotNull('city')
            ->selectRaw('city, COUNT(*) as tickets')
            ->groupBy('city')
            ->orderByDesc('tickets')
            ->orderBy('city')
            ->limit(15)
            ->get()
            ->map(fn ($zeile) => ['city' => (string) $zeile->city, 'tickets' => (int) $zeile->tickets])
            ->all();

        $laender = [];

        foreach ($query->positions()->whereNotNull('country')->selectRaw('country, COUNT(*) as tickets')->groupBy('country')->get() as $zeile) {
            $laender[(string) $zeile->country] = (int) $zeile->tickets;
        }

        arsort($laender);

        return [
            'regions' => array_map(fn ($region, $anzahl) => ['region' => (string) $region, 'tickets' => $anzahl], array_keys($regionen), $regionen),
            'cities' => $orte,
            'countries' => $laender,
            'covered' => $erfasst,
            'total' => $gesamt,
            'coverage' => $gesamt > 0 ? round($erfasst * 100 / $gesamt, 1) : 0.0,
        ];
    }

    /**
     * Orders that were cancelled or expired.
     *
     * COUNTED ON THE ORDER LEVEL, and deliberately past the status filter: a fully
     * cancelled order has no active position at all, so in the position table it
     * simply does not exist - and with the default filter on "paid" it would be
     * removed before it could be counted as cancelled.
     *
     * @return array{by_event: array<string, array{cancelled: int, total: int, share: float}>, buyers_only_cancelled: int}
     */
    public function cancellations(AudienceQuery $query): array
    {
        $zeilen = $query->ordersAnyStatus()
            ->selectRaw('event_slug, status, COUNT(*) as anzahl')
            ->groupBy('event_slug', 'status')
            ->get();

        $abgebrochen = ['c', 'e'];
        $proEvent = [];

        foreach ($zeilen as $zeile) {
            $slug = (string) $zeile->event_slug;
            $proEvent[$slug] ??= ['cancelled' => 0, 'total' => 0, 'share' => 0.0];
            $proEvent[$slug]['total'] += (int) $zeile->anzahl;

            if (in_array((string) $zeile->status, $abgebrochen, true)) {
                $proEvent[$slug]['cancelled'] += (int) $zeile->anzahl;
            }
        }

        foreach ($proEvent as $slug => $werte) {
            $proEvent[$slug]['share'] = $werte['total'] > 0
                ? round($werte['cancelled'] * 100 / $werte['total'], 1)
                : 0.0;
        }

        ksort($proEvent);

        $mitAbbruch = $query->ordersAnyStatus()
            ->whereIn('status', $abgebrochen)
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn ($mail) => mb_strtolower(trim((string) $mail)))
            ->unique();

        // Gegen die AUSWAHL geprueft, nicht gegen den ganzen Bestand: der Block steht
        // unter dieser Auswahl, und wer hier nur Abbrueche hat, fehlt hier in der
        // Kaeuferliste - unabhaengig davon, ob er anderswo ein Ticket haelt.
        $mitTicket = $query->positions()
            ->whereIn('buyer_email', $mitAbbruch->all())
            ->distinct()
            ->pluck('buyer_email');

        return [
            'by_event' => $proEvent,
            'buyers_only_cancelled' => $mitAbbruch->diff($mitTicket)->count(),
        ];
    }

    /**
     * Whatever questions pretix asks at a position, with the answers given.
     *
     * READ FROM THE PAYLOAD because the questions differ per event and change over
     * time; a column per question would need a migration for every new one.
     *
     * @return array<string, array{question: string, answers: array<string, int>}>
     */
    public function questions(AudienceQuery $query): array
    {
        $fragen = [];

        $query->orders()->select('id', 'raw_payload')->orderBy('id')->chunk(200, function ($seite) use (&$fragen) {
            foreach ($seite as $order) {
                foreach ($order->raw_payload['positions'] ?? [] as $position) {
                    if (($position['canceled'] ?? false) === true) {
                        continue;
                    }

                    foreach ($position['answers'] ?? [] as $antwort) {
                        $schluessel = (string) ($antwort['question_identifier'] ?? $antwort['question'] ?? '?');
                        $wert = trim((string) ($antwort['answer'] ?? ''));

                        if ($wert === '') {
                            continue;
                        }

                        $fragen[$schluessel]['question'] ??= $schluessel;
                        $fragen[$schluessel]['answers'][$wert] = ($fragen[$schluessel]['answers'][$wert] ?? 0) + 1;
                    }
                }
            }
        });

        foreach ($fragen as $schluessel => $frage) {
            arsort($fragen[$schluessel]['answers']);
        }

        ksort($fragen);

        return $fragen;
    }

    private function orderValueClass(float $summe): string
    {
        return match (true) {
            $summe <= 10.0 => 'bis 10 €',
            $summe <= 25.0 => '10 bis 25 €',
            $summe <= 50.0 => '25 bis 50 €',
            $summe <= 100.0 => '50 bis 100 €',
            default => 'über 100 €',
        };
    }

    /** @param  array<int, float>  $sortiert */
    private function median(array $sortiert): float
    {
        $anzahl = count($sortiert);
        $mitte = intdiv($anzahl, 2);

        return $anzahl % 2 === 1
            ? round($sortiert[$mitte], 2)
            : round(($sortiert[$mitte - 1] + $sortiert[$mitte]) / 2, 2);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AudienceDimensionsMoreTest`
Expected: PASS, 8 Tests.

- [ ] **Step 5: Run the whole audience suite**

Run: `php artisan test --filter=Audience`
Expected: PASS, alle Tests aus Task 4 bis 9.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Audience/AudienceDimensions.php tests/Feature/Audience/AudienceDimensionsMoreTest.php
git commit -m "Dimensionen: Verlauf, Bestellwert, Gutscheine, Zahlungsart, Herkunft, Storno, Fragen"
```

---

### Task 10: Recht und Seite „Publikum"

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Create: `app/Filament/Pages/AudiencePage.php`
- Create: `resources/views/filament/pages/audience.blade.php`
- Test: `tests/Feature/Audience/AudiencePageTest.php`

**Interfaces:**
- Consumes: alle `Audience*`-Klassen aus Task 4 bis 9
- Produces: Recht `view-audience`; Seite unter `/admin/publikum`; `AudiencePage::currentQuery(): AudienceQuery` baut die Auswahl aus dem Formular

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Audience/AudiencePageTest.php`:

```php
<?php

namespace Tests\Feature\Audience;

use App\Filament\Pages\AudiencePage;
use App\Models\Customer;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

class AudiencePageTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'event_date' => '2026-07-10', 'is_active' => true]);
        Event::create(['name' => 'Winterball', 'pretix_event_slug' => 'winterball', 'event_date' => '2026-12-12', 'is_active' => true]);

        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3, 'price' => 40.0]]);
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7, 'price' => 25.0]]);
        $this->pretixOrder('winterball', 'W2', 'bodo@example.test', [['item' => 7, 'price' => 25.0]]);
    }

    private function loginAs(string $role, ?int $customerId = null): User
    {
        $user = User::factory()->create(['customer_id' => $customerId]);
        $user->assignRole(Role::findByName($role));
        $this->actingAs($user);

        return $user;
    }

    public function test_an_admin_can_open_the_page(): void
    {
        $this->loginAs('admin');

        $this->get(AudiencePage::getUrl())->assertSuccessful();
    }

    public function test_a_user_without_the_permission_is_kept_out(): void
    {
        $this->loginAs('auditor');

        $this->assertFalse(AudiencePage::canAccess());
    }

    public function test_the_headline_figures_reach_the_page(): void
    {
        $this->loginAs('admin');

        $zahlen = Livewire::test(AudiencePage::class)->instance()->stats;

        $this->assertSame(2, $zahlen['buyers']);
        $this->assertSame(3, $zahlen['tickets']);
        $this->assertSame(1, $zahlen['multi_event_buyers']);
    }

    public function test_choosing_one_event_really_narrows_the_figures(): void
    {
        $this->loginAs('admin');

        $seite = Livewire::test(AudiencePage::class)
            ->set('data.event_slugs', ['winterball']);

        $zahlen = $seite->instance()->stats;

        $this->assertSame(2, $zahlen['buyers']);
        $this->assertSame(2, $zahlen['tickets']);
        $this->assertSame(0, $zahlen['multi_event_buyers']);
    }

    public function test_the_status_filter_really_applies(): void
    {
        $this->loginAs('admin');
        $this->pretixOrder('sommerfest', 'S9', 'clara@example.test', [['item' => 3]], status: 'c');

        $seite = Livewire::test(AudiencePage::class);
        $this->assertSame(3, $seite->instance()->stats['tickets']);

        $seite->set('data.statuses', ['p', 'c']);
        $this->assertSame(4, $seite->instance()->stats['tickets']);
    }

    public function test_a_promoter_sees_only_their_own_event(): void
    {
        $kunde = Customer::create(['name' => 'Verein Nord', 'is_active' => true]);
        Event::where('pretix_event_slug', 'sommerfest')->update(['customer_id' => $kunde->id]);

        $this->loginAs('customer', $kunde->id);

        $zahlen = Livewire::test(AudiencePage::class)->instance()->stats;

        $this->assertSame(1, $zahlen['tickets']);
    }

    public function test_an_event_without_orders_is_offered_with_a_hint(): void
    {
        Event::create(['name' => 'Fruehlingsfest', 'pretix_event_slug' => 'fruehling', 'is_active' => true]);

        $this->loginAs('admin');

        $seite = Livewire::test(AudiencePage::class);

        $this->assertContains('fruehling', $seite->get('data.event_slugs'));
        $seite->assertSee('Fruehlingsfest (noch keine Bestellungen)');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AudiencePageTest`
Expected: FAIL mit „Class App\Filament\Pages\AudiencePage does not exist".

- [ ] **Step 3: Add the permission**

In `database/seeders/RolesAndPermissionsSeeder.php`:

- In `self::PERMISSIONS` nach `'view-reports',` ergänzen: `'view-audience',`
- Im `$customer->syncPermissions([...])`-Block ergänzen: `'view-audience',`

Der Admin bekommt es über `syncPermissions(self::PERMISSIONS)` automatisch. Manager und Auditor bleiben außen vor: die Auswertung zeigt Personendaten, und wer Zahlen prüft, braucht dafür keine Käuferliste.

- [ ] **Step 4: Write the page**

Create `app/Filament/Pages/AudiencePage.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Models\Event;
use App\Models\PretixItem;
use App\Services\Audience\AudienceDimensions;
use App\Services\Audience\AudienceOverlap;
use App\Services\Audience\AudienceQuery;
use App\Services\Audience\AudienceStats;
use App\Support\CustomerScope;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Who comes to which events - and what that says about the programme.
 *
 * THE SELECTION IS THE SCREEN: pick a few events, and everything below answers for
 * exactly those. Every figure is built by the Audience services, so this page holds
 * no arithmetic of its own - and no way past the customer scope that sits in
 * AudienceQuery.
 */
class AudiencePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Berichte';

    protected static ?string $navigationLabel = 'Publikum';

    protected static ?string $title = 'Publikum';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'publikum';

    protected static string $view = 'filament.pages.audience';

    public const STATUS_OPTIONS = [
        'p' => 'bezahlt',
        'n' => 'offen',
        'e' => 'abgelaufen',
        'c' => 'storniert',
    ];

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-audience') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            // Alle sichtbaren Veranstaltungen: die Frage lautet „wer war wo", und die
            // beantwortet sich erst über mehrere Veranstaltungen hinweg.
            'event_slugs' => array_keys($this->eventOptions()),
            'statuses' => ['p'],
            'item_ids' => [],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Auswahl')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('event_slugs')
                            ->label('Veranstaltungen')
                            ->multiple()
                            ->options(fn () => $this->eventOptions())
                            ->searchable()
                            ->helperText('Nichts ausgewählt = alle sichtbaren Veranstaltungen.')
                            ->afterStateUpdated(fn (callable $set) => $set('item_ids', []))
                            ->live(),

                        Forms\Components\Select::make('statuses')
                            ->label('Bestellstatus')
                            ->multiple()
                            ->options(self::STATUS_OPTIONS)
                            ->helperText('Voreinstellung: nur bezahlte Bestellungen.')
                            ->live(),

                        Forms\Components\Select::make('item_ids')
                            ->label('Ticketarten')
                            ->multiple()
                            ->options(fn (callable $get) => $this->itemOptions($get('event_slugs') ?? []))
                            ->searchable()
                            ->helperText('Nichts ausgewählt = alle Ticketarten.')
                            ->live(),

                        Forms\Components\Group::make([
                            Forms\Components\DatePicker::make('from')->label('Bestellt ab')->live(),
                            Forms\Components\DatePicker::make('until')->label('Bestellt bis')->live(),
                        ])->columns(2),
                    ]),
            ])
            ->statePath('data');
    }

    /** The current selection, as the services want it. */
    public function currentQuery(): AudienceQuery
    {
        return new AudienceQuery(
            eventSlugs: $this->data['event_slugs'] ?? [],
            statuses: $this->data['statuses'] ?? [],
            itemIds: $this->data['item_ids'] ?? [],
            from: filled($this->data['from'] ?? null) ? Carbon::parse($this->data['from']) : null,
            until: filled($this->data['until'] ?? null) ? Carbon::parse($this->data['until']) : null,
        );
    }

    public function getStatsProperty(): array
    {
        return app(AudienceStats::class)->forSelection($this->currentQuery());
    }

    public function getOverlapProperty(): array
    {
        return app(AudienceOverlap::class)->matrix($this->currentQuery());
    }

    public function getFirstTimeProperty(): array
    {
        return app(AudienceOverlap::class)->firstTimeByEvent($this->currentQuery());
    }

    public function getDimensionsProperty(): AudienceDimensions
    {
        return app(AudienceDimensions::class);
    }

    /**
     * The readable name of an event, for headings and matrix labels.
     *
     * Reads the plain name rather than the picker label: the picker marks events
     * without orders, and that marking has no business in a table header.
     */
    public function eventLabel(string $slug): string
    {
        return Event::namesBySlug()[$slug] ?? $slug;
    }

    /**
     * The events that can be chosen, with a hint where nothing was sold yet.
     *
     * AN EVENT WITHOUT ORDERS STAYS IN THE LIST: it is set up, it simply has no
     * tickets yet, and leaving it out would look like it was never configured. The
     * hint is what keeps someone from reading an empty result as a defect.
     *
     * @return array<string, string>
     */
    private function eventOptions(): array
    {
        $slugs = CustomerScope::byEventSlug(
            Event::query()->whereNotNull('pretix_event_slug'),
            'pretix_event_slug',
        )->orderBy('id')->pluck('pretix_event_slug')->unique();

        $namen = Event::namesBySlug();

        $mitBestellungen = \App\Models\PretixPosition::query()
            ->whereIn('event_slug', $slugs->all())
            ->distinct()
            ->pluck('event_slug')
            ->all();

        $optionen = [];

        foreach ($slugs as $slug) {
            $name = $namen[$slug] ?? $slug;

            $optionen[$slug] = in_array($slug, $mitBestellungen, true)
                ? $name
                : $name . ' (noch keine Bestellungen)';
        }

        asort($optionen);

        return $optionen;
    }

    /**
     * @param  array<int, string>  $slugs
     * @return array<int, string>
     */
    private function itemOptions(array $slugs): array
    {
        $erlaubt = $slugs !== [] ? $slugs : array_keys($this->eventOptions());

        return PretixItem::query()
            ->whereIn('event_slug', $erlaubt)
            ->orderBy('name')
            ->pluck('name', 'item_id')
            ->all();
    }
}
```

- [ ] **Step 5: Write the minimal view**

Create `resources/views/filament/pages/audience.blade.php`:

```blade
<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <div class="aud-kacheln">
        <x-filament::section>
            <div class="aud-klein">Käufer</div>
            <div class="aud-gross">{{ number_format($this->stats['buyers'], 0, ',', '.') }}</div>
            <div class="aud-fein">{{ number_format($this->stats['multi_event_buyers'], 0, ',', '.') }} bei mehreren Veranstaltungen</div>
        </x-filament::section>

        <x-filament::section>
            <div class="aud-klein">Tickets</div>
            <div class="aud-gross">{{ number_format($this->stats['tickets'], 0, ',', '.') }}</div>
            <div class="aud-fein">aus {{ number_format($this->stats['orders'], 0, ',', '.') }} Bestellungen</div>
        </x-filament::section>

        <x-filament::section>
            <div class="aud-klein">Umsatz</div>
            <div class="aud-gross">{{ number_format($this->stats['revenue'], 2, ',', '.') }} €</div>
            <div class="aud-fein">Ticketpreise aus pretix</div>
        </x-filament::section>

        <x-filament::section>
            <div class="aud-klein">Anteil wiederkehrend</div>
            <div class="aud-gross">{{ number_format($this->stats['returning_share'], 1, ',', '.') }} %</div>
            <div class="aud-fein">{{ number_format($this->stats['tickets_without_buyer'], 0, ',', '.') }} Tickets ohne Käuferadresse</div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AudiencePageTest`
Expected: PASS, 7 Tests.

- [ ] **Step 7: Commit**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php app/Filament/Pages/AudiencePage.php resources/views/filament/pages/audience.blade.php tests/Feature/Audience/AudiencePageTest.php
git commit -m "Seite Publikum mit Auswahl und Kopfzahlen"
```

---

### Task 11: Käuferliste als Tabelle auf der Seite

**Files:**
- Modify: `app/Filament/Pages/AudiencePage.php`
- Modify: `resources/views/filament/pages/audience.blade.php`
- Test: `tests/Feature/Audience/AudienceBuyerTableTest.php`

**Interfaces:**
- Consumes: `AudienceBuyers` aus Task 7
- Produces: `AudiencePage` implementiert `HasTable` und zeigt die Käuferliste

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Audience/AudienceBuyerTableTest.php`:

```php
<?php

namespace Tests\Feature\Audience;

use App\Filament\Pages\AudiencePage;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

class AudienceBuyerTableTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);

        Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'is_active' => true]);
        Event::create(['name' => 'Winterball', 'pretix_event_slug' => 'winterball', 'is_active' => true]);

        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3, 'price' => 40.0]], address: ['name' => 'Anna Beispiel']);
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7, 'price' => 25.0]], address: ['name' => 'Anna Beispiel']);
        $this->pretixOrder('winterball', 'W2', 'bodo@example.test', [['item' => 7, 'price' => 25.0]], address: ['name' => 'Bodo Klein']);
    }

    public function test_the_table_lists_one_row_per_buyer(): void
    {
        $seite = Livewire::test(AudiencePage::class);

        $seite->assertSee('anna@example.test')->assertSee('bodo@example.test');

        // Anna hat zwei Bestellungen an zwei Veranstaltungen und trotzdem eine Zeile.
        $this->assertCount(2, $seite->instance()->getTableRecords());
    }

    public function test_it_can_be_sorted_by_tickets(): void
    {
        Livewire::test(AudiencePage::class)
            ->sortTable('tickets', 'desc')
            ->assertSuccessful();
    }

    public function test_searching_is_case_insensitive(): void
    {
        Livewire::test(AudiencePage::class)
            ->searchTable('ANNA')
            ->assertSee('anna@example.test')
            ->assertDontSee('bodo@example.test');
    }

    public function test_narrowing_the_events_narrows_the_table(): void
    {
        Livewire::test(AudiencePage::class)
            ->set('data.event_slugs', ['sommerfest'])
            ->assertSee('anna@example.test')
            ->assertDontSee('bodo@example.test');
    }

    public function test_the_table_offers_no_page_size_above_two_hundred(): void
    {
        $optionen = Livewire::test(AudiencePage::class)->instance()->getTable()->getPaginationPageOptions();

        $this->assertSame([25, 50, 100, 200], $optionen);
        $this->assertLessThanOrEqual(200, (int) max($optionen));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AudienceBuyerTableTest`
Expected: FAIL — die Seite hat noch keine Tabelle, `assertCanSeeTableRecords` findet keinen Tabellenkontext.

- [ ] **Step 3: Add the table to the page**

In `app/Filament/Pages/AudiencePage.php`:

Die Klassendeklaration ersetzen durch:

```php
class AudiencePage extends Page implements HasForms, \Filament\Tables\Contracts\HasTable
```

Nach `use InteractsWithForms;` ergänzen:

```php
    use \Filament\Tables\Concerns\InteractsWithTable;
```

Und am Ende der Klasse, vor `eventOptions()`, einfügen:

```php
    /**
     * The buyer list.
     *
     * A REAL TABLE rather than a rendered array: this is the list people work in -
     * sort by tickets, search for a name, page through it, take it away as a file.
     * All of that is free here and hand-built anywhere else.
     */
    public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        return $table
            ->query(fn () => app(\App\Services\Audience\AudienceBuyers::class)->query($this->currentQuery()))
            ->defaultSort('tickets', 'desc')
            /*
             * 200 IS THE CEILING HERE, below the global policy's 500: this query
             * groups over every position of the selection, so a large page repeats
             * that work on every reload. Because the option does not exist, nothing
             * has to be clamped back afterwards.
             */
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)
            ->heading('Käufer')
            ->description('Eine Zeile je E-Mail-Adresse, über die gewählten Veranstaltungen hinweg.')
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('buyer_email')
                    ->label('E-Mail')
                    ->searchable(query: fn ($query, string $search) => \App\Services\Audience\AudienceBuyers::search($query, $search))
                    ->copyable(),

                \Filament\Tables\Columns\TextColumn::make('buyer_display_name')
                    ->label('Name')
                    ->placeholder('ohne Angabe'),

                \Filament\Tables\Columns\TextColumn::make('events')
                    ->label('Veranstaltungen')
                    ->sortable()
                    ->alignEnd()
                    // $state, nicht $s: Filament reicht die Argumente über den
                    // Parameternamen herein, ein anderer Name gibt einen 500er.
                    ->tooltip(fn ($state, $record) => implode(', ', app(\App\Services\Audience\AudienceBuyers::class)
                        ->eventNames($this->currentQuery(), (string) $record->buyer_email))),

                \Filament\Tables\Columns\TextColumn::make('orders')->label('Bestellungen')->sortable()->alignEnd(),
                \Filament\Tables\Columns\TextColumn::make('tickets')->label('Tickets')->sortable()->alignEnd(),

                \Filament\Tables\Columns\TextColumn::make('revenue')
                    ->label('Umsatz')
                    ->sortable()
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.') . ' €'),

                \Filament\Tables\Columns\TextColumn::make('first_at')
                    ->label('Erste Bestellung')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state ? \Illuminate\Support\Carbon::parse($state)->format('d.m.Y') : ''),

                \Filament\Tables\Columns\TextColumn::make('last_at')
                    ->label('Letzte Bestellung')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state ? \Illuminate\Support\Carbon::parse($state)->format('d.m.Y') : ''),
            ])
            ->emptyStateHeading('Keine Käufer in dieser Auswahl')
            ->emptyStateDescription('Prüfe die gewählten Veranstaltungen, den Bestellstatus und den Zeitraum. Ohne pretix-Import liegen noch keine Bestellungen vor.');
    }
```

- [ ] **Step 4: Show the table in the view**

Am Ende von `resources/views/filament/pages/audience.blade.php`, vor `</x-filament-panels::page>`, einfügen:

```blade
    <div class="aud">
        {{ $this->table }}
    </div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AudienceBuyerTableTest`
Expected: PASS, 5 Tests.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/AudiencePage.php resources/views/filament/pages/audience.blade.php tests/Feature/Audience/AudienceBuyerTableTest.php
git commit -m "Kaeuferliste als sortierbare Tabelle auf der Publikumsseite"
```

---

### Task 12: Überschneidung und Dimensionen in der Oberfläche

**Files:**
- Modify: `resources/views/filament/pages/audience.blade.php`
- Create: `resources/views/filament/pages/partials/audience-verteilung.blade.php`
- Modify: `resources/views/filament/adminlte-theme.blade.php`
- Test: `tests/Feature/Audience/AudienceViewTest.php`

**Interfaces:**
- Consumes: `AudiencePage::$overlap`, `$firstTime`, `$dimensions`, `eventLabel()` aus Task 10
- Produces: die vollständige Seite

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Audience/AudienceViewTest.php`:

```php
<?php

namespace Tests\Feature\Audience;

use App\Filament\Pages\AudiencePage;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

/**
 * The page has to RENDER the figures, not only compute them.
 *
 * A smoke test that only asks for HTTP 200 would pass with an empty page; these
 * assertions name values that can only appear when the sections are really drawn.
 */
class AudienceViewTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);

        Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'event_date' => '2026-07-10', 'is_active' => true]);
        Event::create(['name' => 'Winterball', 'pretix_event_slug' => 'winterball', 'event_date' => '2026-12-12', 'is_active' => true]);

        $this->ticketType('sommerfest', 3, 'VIP');
        $this->ticketType('winterball', 7, 'VIP');

        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3, 'price' => 40.0]], orderedAt: '2026-07-01 09:00:00');
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7, 'price' => 25.0]], orderedAt: '2026-11-01 09:00:00');
        $this->pretixOrder('winterball', 'W2', 'bodo@example.test', [['item' => 7, 'price' => 25.0, 'voucher' => '55']], orderedAt: '2026-11-02 09:00:00');
    }

    public function test_the_page_shows_every_section(): void
    {
        $antwort = $this->get(AudiencePage::getUrl());

        $antwort->assertSuccessful();
        $antwort->assertSee('Überschneidung', false);
        $antwort->assertSee('Neu und wiederkehrend', false);
        $antwort->assertSee('Ticketarten', false);
        $antwort->assertSee('Vorlaufzeit', false);
        $antwort->assertSee('Gruppengröße', false);
        $antwort->assertSee('Verkaufsverlauf', false);
        $antwort->assertSee('Tage vor der Veranstaltung', false);
        $antwort->assertSee('Bestellwert', false);
        $antwort->assertSee('Gutschein', false);
        $antwort->assertSee('Zahlungsart', false);
        $antwort->assertSee('Herkunft', false);
        $antwort->assertSee('Storno', false);
    }

    public function test_the_matrix_carries_the_event_names_and_the_shared_count(): void
    {
        $antwort = $this->get(AudiencePage::getUrl());

        $antwort->assertSee('Sommerfest', false);
        $antwort->assertSee('Winterball', false);
        // anna ist die gemeinsame Kaeuferin beider Veranstaltungen.
        $antwort->assertSee('100,0&nbsp;%', false);
    }

    public function test_the_origin_block_names_its_coverage(): void
    {
        $antwort = $this->get(AudiencePage::getUrl());

        $antwort->assertSee('Abdeckung', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AudienceViewTest`
Expected: FAIL — „Überschneidung" steht noch nicht auf der Seite.

- [ ] **Step 3: Write the shared distribution partial**

Create `resources/views/filament/pages/partials/audience-verteilung.blade.php`:

```blade
{{--
    Eine Verteilung als Balkenliste: Beschriftung, Anzahl, Anteil.

    Als Tabelle mit CSS-Balken statt als Diagramm, weil die Zahl daneben die
    eigentliche Antwort ist und ein Balken ohne sie nur eine Richtung zeigt.
--}}
@php
    $summe = array_sum($rows) ?: 1;
@endphp

<table class="aud-tabelle">
    <thead>
        <tr>
            <th>{{ $labelHeading ?? 'Klasse' }}</th>
            <th class="num">Anzahl</th>
            <th class="num">Anteil</th>
            <th class="aud-balkenspalte"></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $label => $anzahl)
            <tr>
                <td>{{ $label }}</td>
                <td class="num">{{ number_format($anzahl, 0, ',', '.') }}</td>
                <td class="num">{{ number_format($anzahl * 100 / $summe, 1, ',', '.') }}&nbsp;%</td>
                <td class="aud-balkenspalte">
                    <span class="aud-balken" style="width: {{ round($anzahl * 100 / $summe) }}%"></span>
                </td>
            </tr>
        @empty
            <tr><td colspan="4">Für diese Auswahl liegen keine Werte vor.</td></tr>
        @endforelse
    </tbody>
</table>
```

- [ ] **Step 4: Write the full page view**

Ersetze `resources/views/filament/pages/audience.blade.php` vollständig:

```blade
<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @php
        $stats = $this->stats;
        $overlap = $this->overlap;
        $firstTime = $this->firstTime;
        $auswahl = $this->currentQuery();
        $dim = $this->dimensions;

        $ticketarten = $dim->ticketTypes($auswahl);
        $treue = $dim->sameTypeAcrossEvents($auswahl);
        $vorlauf = $dim->leadTime($auswahl);
        $gruppen = $dim->groupSize($auswahl);
        $verlauf = $dim->salesCurve($auswahl);
        $werte = $dim->orderValue($auswahl);
        $gutscheine = $dim->vouchers($auswahl);
        $zahlarten = $dim->paymentProviders($auswahl);
        $herkunft = $dim->origin($auswahl);
        $storno = $dim->cancellations($auswahl);
        $fragen = $dim->questions($auswahl);
    @endphp

    <div class="aud-kacheln">
        <x-filament::section>
            <div class="aud-klein">Käufer</div>
            <div class="aud-gross">{{ number_format($stats['buyers'], 0, ',', '.') }}</div>
            <div class="aud-fein">{{ number_format($stats['multi_event_buyers'], 0, ',', '.') }} bei mehreren Veranstaltungen</div>
        </x-filament::section>

        <x-filament::section>
            <div class="aud-klein">Tickets</div>
            <div class="aud-gross">{{ number_format($stats['tickets'], 0, ',', '.') }}</div>
            <div class="aud-fein">aus {{ number_format($stats['orders'], 0, ',', '.') }} Bestellungen</div>
        </x-filament::section>

        <x-filament::section>
            <div class="aud-klein">Umsatz</div>
            <div class="aud-gross">{{ number_format($stats['revenue'], 2, ',', '.') }} €</div>
            <div class="aud-fein">Ticketpreise aus pretix</div>
        </x-filament::section>

        <x-filament::section>
            <div class="aud-klein">Anteil wiederkehrend</div>
            <div class="aud-gross">{{ number_format($stats['returning_share'], 1, ',', '.') }}&nbsp;%</div>
            <div class="aud-fein">{{ number_format($stats['tickets_without_buyer'], 0, ',', '.') }} Tickets ohne Käuferadresse</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Überschneidung der Veranstaltungen">
        <p class="aud-hinweis">
            Gelesen von links nach rechts: von den Käufern der Zeile waren so viele auch bei der Veranstaltung der Spalte.
        </p>

        <div class="aud-wrap">
            <table class="aud-tabelle">
                <thead>
                    <tr>
                        <th>Veranstaltung</th>
                        <th class="num">Käufer</th>
                        @foreach ($overlap['events'] as $spalte)
                            <th class="num">{{ $this->eventLabel($spalte) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($overlap['rows'] as $slug => $zeile)
                        <tr>
                            <td>{{ $this->eventLabel($slug) }}</td>
                            <td class="num">{{ number_format($zeile['buyers'], 0, ',', '.') }}</td>
                            @foreach ($overlap['events'] as $spalte)
                                <td class="num {{ $slug === $spalte ? 'aud-diagonale' : '' }}">
                                    {{ number_format($zeile['shared'][$spalte]['count'], 0, ',', '.') }}
                                    <span class="aud-fein">{{ number_format($zeile['shared'][$spalte]['share'], 1, ',', '.') }}&nbsp;%</span>
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="2">Für diese Auswahl liegen keine Käufer vor.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Neu und wiederkehrend je Veranstaltung">
        <p class="aud-hinweis">
            Wiederkehrend heißt: die früheste Bestellung dieser Person gehört zu einer anderen Veranstaltung.
        </p>

        <table class="aud-tabelle">
            <thead>
                <tr>
                    <th>Veranstaltung</th>
                    <th class="num">Erstkäufer</th>
                    <th class="num">Wiederkehrend</th>
                    <th class="num">Anteil wiederkehrend</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($firstTime as $slug => $zahlen)
                    <tr>
                        <td>{{ $this->eventLabel($slug) }}</td>
                        <td class="num">{{ number_format($zahlen['first_time'], 0, ',', '.') }}</td>
                        <td class="num">{{ number_format($zahlen['returning'], 0, ',', '.') }}</td>
                        <td class="num">{{ number_format($zahlen['share'], 1, ',', '.') }}&nbsp;%</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Für diese Auswahl liegen keine Käufer vor.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <div class="aud">
        {{ $this->table }}
    </div>

    <x-filament::section heading="Ticketarten">
        <p class="aud-hinweis">
            {{ number_format($treue['loyal'], 0, ',', '.') }} von {{ number_format($treue['buyers'], 0, ',', '.') }}
            Personen mit mehreren Veranstaltungen wählen überall dieselbe Ticketart
            ({{ number_format($treue['share'], 1, ',', '.') }}&nbsp;%). Verglichen wird der Name der Ticketart.
        </p>

        <table class="aud-tabelle">
            <thead>
                <tr>
                    <th>Veranstaltung</th>
                    <th>Ticketart</th>
                    <th class="num">Tickets</th>
                    <th class="num">Umsatz</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($ticketarten as $art)
                    <tr>
                        <td>{{ $this->eventLabel($art['event']) }}</td>
                        <td>{{ $art['name'] }}</td>
                        <td class="num">{{ number_format($art['tickets'], 0, ',', '.') }}</td>
                        <td class="num">{{ number_format($art['revenue'], 2, ',', '.') }} €</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Für diese Auswahl liegen keine Tickets vor.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Vorlaufzeit">
        <p class="aud-hinweis">
            Tage zwischen Bestellung und Veranstaltungstag.
            {{ number_format($vorlauf['covered'], 0, ',', '.') }} Tickets sind erfasst,
            {{ number_format($vorlauf['uncovered'], 0, ',', '.') }} gehören zu Veranstaltungen ohne gepflegtes Datum.
        </p>

        @include('filament.pages.partials.audience-verteilung', ['rows' => $vorlauf['classes'], 'labelHeading' => 'Vorlauf'])
    </x-filament::section>

    <x-filament::section heading="Gruppengröße">
        <p class="aud-hinweis">
            Tickets je Bestellung, im Mittel {{ number_format($gruppen['average'], 2, ',', '.') }}.
            Bei {{ number_format($gruppen['with_other_attendee'], 0, ',', '.') }} von
            {{ number_format($gruppen['comparable'], 0, ',', '.') }} vergleichbaren Tickets steht ein anderer Name
            auf dem Ticket als in der Rechnungsadresse.
        </p>

        @include('filament.pages.partials.audience-verteilung', ['rows' => $gruppen['classes'], 'labelHeading' => 'Tickets je Bestellung'])
    </x-filament::section>

    <x-filament::section heading="Verkaufsverlauf">
        <div class="aud-spalten">
            <div>
                <h4 class="aud-klein">Nach Wochentag</h4>
                @include('filament.pages.partials.audience-verteilung', ['rows' => $verlauf['by_weekday'], 'labelHeading' => 'Wochentag'])
            </div>
            <div>
                <h4 class="aud-klein">Nach Tageszeit</h4>
                @include('filament.pages.partials.audience-verteilung', ['rows' => $verlauf['by_hour_block'], 'labelHeading' => 'Uhrzeit'])
            </div>
        </div>

        <h4 class="aud-klein">Bestellungen je Tag</h4>
        @include('filament.pages.partials.audience-verteilung', ['rows' => $verlauf['by_day'], 'labelHeading' => 'Tag'])

        <h4 class="aud-klein">Tage vor der Veranstaltung</h4>
        <p class="aud-hinweis">
            Auf dieser Achse liegen mehrere Veranstaltungen übereinander und lassen sich vergleichen.
            {{ number_format($verlauf['without_event_date'], 0, ',', '.') }} Bestellungen gehören zu
            Veranstaltungen ohne gepflegtes Datum und fehlen hier.
        </p>
        @include('filament.pages.partials.audience-verteilung', ['rows' => $verlauf['by_days_before'], 'labelHeading' => 'Tage vorher'])
    </x-filament::section>

    <x-filament::section heading="Bestellwert">
        <p class="aud-hinweis">
            Median {{ number_format($werte['median'], 2, ',', '.') }} €,
            Mittelwert {{ number_format($werte['average'], 2, ',', '.') }} €.
        </p>

        @include('filament.pages.partials.audience-verteilung', ['rows' => $werte['classes'], 'labelHeading' => 'Bestellwert'])
    </x-filament::section>

    <x-filament::section heading="Gutscheine">
        <p class="aud-hinweis">
            {{ number_format($gutscheine['with'], 0, ',', '.') }} von
            {{ number_format($gutscheine['total'], 0, ',', '.') }} Tickets tragen einen Gutschein
            ({{ number_format($gutscheine['share'], 1, ',', '.') }}&nbsp;%). pretix liefert dabei die Kennung
            des Gutscheins.
        </p>

        <table class="aud-tabelle">
            <thead>
                <tr><th>Gutschein-Kennung</th><th class="num">Tickets</th></tr>
            </thead>
            <tbody>
                @forelse ($gutscheine['top'] as $gutschein)
                    <tr>
                        <td>{{ $gutschein['voucher'] }}</td>
                        <td class="num">{{ number_format($gutschein['tickets'], 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2">In dieser Auswahl wurde kein Gutschein eingelöst.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Zahlungsart">
        @include('filament.pages.partials.audience-verteilung', ['rows' => $zahlarten, 'labelHeading' => 'Zahlungsart'])
    </x-filament::section>

    <x-filament::section heading="Herkunft">
        <p class="aud-hinweis">
            Abdeckung: {{ number_format($herkunft['coverage'], 1, ',', '.') }}&nbsp;% der Tickets tragen eine
            Rechnungsadresse ({{ number_format($herkunft['covered'], 0, ',', '.') }} von
            {{ number_format($herkunft['total'], 0, ',', '.') }}). Die Verteilung beschreibt diesen Teil.
        </p>

        <div class="aud-spalten">
            <div>
                <h4 class="aud-klein">Nach PLZ-Region</h4>
                <table class="aud-tabelle">
                    <thead><tr><th>Region</th><th class="num">Tickets</th></tr></thead>
                    <tbody>
                        @forelse ($herkunft['regions'] as $region)
                            <tr><td>{{ $region['region'] }}</td><td class="num">{{ number_format($region['tickets'], 0, ',', '.') }}</td></tr>
                        @empty
                            <tr><td colspan="2">Keine Adressen in dieser Auswahl.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>
                <h4 class="aud-klein">Häufigste Orte</h4>
                <table class="aud-tabelle">
                    <thead><tr><th>Ort</th><th class="num">Tickets</th></tr></thead>
                    <tbody>
                        @forelse ($herkunft['cities'] as $ort)
                            <tr><td>{{ $ort['city'] }}</td><td class="num">{{ number_format($ort['tickets'], 0, ',', '.') }}</td></tr>
                        @empty
                            <tr><td colspan="2">Keine Adressen in dieser Auswahl.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Storno und Ablauf">
        <p class="aud-hinweis">
            Bestellungen, die storniert wurden oder abgelaufen sind, gemessen an allen Bestellungen der
            Veranstaltung. {{ number_format($storno['buyers_only_cancelled'], 0, ',', '.') }} Personen haben
            ausschließlich solche Bestellungen und fehlen deshalb in der Käuferliste.
        </p>

        <table class="aud-tabelle">
            <thead>
                <tr><th>Veranstaltung</th><th class="num">Storniert/abgelaufen</th><th class="num">Bestellungen</th><th class="num">Anteil</th></tr>
            </thead>
            <tbody>
                @forelse ($storno['by_event'] as $slug => $zahlen)
                    <tr>
                        <td>{{ $this->eventLabel($slug) }}</td>
                        <td class="num">{{ number_format($zahlen['cancelled'], 0, ',', '.') }}</td>
                        <td class="num">{{ number_format($zahlen['total'], 0, ',', '.') }}</td>
                        <td class="num">{{ number_format($zahlen['share'], 1, ',', '.') }}&nbsp;%</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Für diese Auswahl liegen keine Bestellungen vor.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    @if ($fragen !== [])
        <x-filament::section heading="Antworten aus den Bestellungen">
            @foreach ($fragen as $schluessel => $frage)
                <h4 class="aud-klein">{{ $frage['question'] }}</h4>
                @include('filament.pages.partials.audience-verteilung', ['rows' => $frage['answers'], 'labelHeading' => 'Antwort'])
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
```

- [ ] **Step 5: Add the CSS to the theme**

In `resources/views/filament/adminlte-theme.blade.php`, im vorhandenen `<style>`-Block hinter den `.rpt`-Regeln einfügen:

```css
/* Publikumsauswertung. Eigenes CSS, weil es keinen Tailwind-Build gibt:
   Arbitrary-Klassen existieren im ausgelieferten Filament-CSS nicht. */
.aud-kacheln { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem; }
.aud-klein { font-size: .8rem; color: #6b7280; margin-bottom: .15rem; }
.aud-gross { font-size: 1.6rem; font-weight: 700; line-height: 1.15; }
.aud-fein { font-size: .75rem; color: #9ca3af; }
.aud-hinweis { font-size: .85rem; color: #6b7280; margin-bottom: .75rem; }
.aud-wrap { overflow-x: auto; }
.aud-spalten { display: grid; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); gap: 1.5rem; }
.aud-tabelle { width: 100%; border-collapse: collapse; font-size: .85rem; }
.aud-tabelle th, .aud-tabelle td { padding: .3rem .5rem; border-bottom: 1px solid rgba(128, 128, 128, .2); text-align: left; }
.aud-tabelle th.num, .aud-tabelle td.num { text-align: right; }
.aud-tabelle thead th { font-weight: 600; color: #6b7280; }
.aud-balkenspalte { width: 30%; }
.aud-balken { display: block; height: .55rem; border-radius: .25rem; background: rgb(var(--primary-500, 59 130 246)); min-width: 2px; }
.aud-diagonale { font-weight: 600; }

/* Dark-Mode: die hellen Grautöne würden Filaments dunkle Palette überdecken. */
.dark .aud-klein, .dark .aud-hinweis, .dark .aud-tabelle thead th { color: #9ca3af; }
.dark .aud-fein { color: #6b7280; }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AudienceViewTest`
Expected: PASS, 3 Tests.

- [ ] **Step 7: Commit**

```bash
git add resources/views/filament/pages/audience.blade.php resources/views/filament/pages/partials/audience-verteilung.blade.php resources/views/filament/adminlte-theme.blade.php tests/Feature/Audience/AudienceViewTest.php
git commit -m "Ueberschneidungsmatrix und alle Dimensionen auf der Publikumsseite"
```

---

### Task 13: Export der Käuferliste und der Matrix

**Files:**
- Create: `app/Exports/AudienceBuyersExport.php`
- Create: `app/Exports/AudienceOverlapExport.php`
- Modify: `app/Filament/Pages/AudiencePage.php`
- Test: `tests/Feature/Audience/AudienceExportTest.php`

**Interfaces:**
- Consumes: `AudienceBuyers` aus Task 7, `AudienceOverlap` aus Task 6
- Produces: zwei Tabellen-Aktionen `Käuferliste herunterladen` und `Überschneidung herunterladen`, je als CSV und XLSX

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Audience/AudienceExportTest.php`:

```php
<?php

namespace Tests\Feature\Audience;

use App\Exports\AudienceBuyersExport;
use App\Exports\AudienceOverlapExport;
use App\Filament\Pages\AudiencePage;
use App\Models\Event;
use App\Models\User;
use App\Services\Audience\AudienceQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

class AudienceExportTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);

        Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'is_active' => true]);
        Event::create(['name' => 'Winterball', 'pretix_event_slug' => 'winterball', 'is_active' => true]);

        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3, 'price' => 40.0]], address: ['name' => 'Anna Beispiel']);
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7, 'price' => 25.0]], address: ['name' => 'Anna Beispiel']);
    }

    public function test_the_buyer_export_has_a_heading_row_and_one_row_per_buyer(): void
    {
        $export = new AudienceBuyersExport(new AudienceQuery());

        $this->assertSame(
            ['E-Mail', 'Name', 'Veranstaltungen', 'Bestellungen', 'Tickets', 'Umsatz', 'Erste Bestellung', 'Letzte Bestellung'],
            $export->headings(),
        );

        $zeilen = $export->array();

        $this->assertCount(1, $zeilen);
        $this->assertSame('anna@example.test', $zeilen[0][0]);
        $this->assertSame('Sommerfest, Winterball', $zeilen[0][2]);
        $this->assertSame(2, $zeilen[0][4]);
    }

    public function test_the_overlap_export_is_a_square_of_events(): void
    {
        $export = new AudienceOverlapExport(new AudienceQuery());

        $this->assertSame(['Veranstaltung', 'Käufer', 'Sommerfest', 'Winterball'], $export->headings());

        $zeilen = $export->array();

        $this->assertCount(2, $zeilen);
        $this->assertSame('Sommerfest', $zeilen[0][0]);
        $this->assertSame(1, $zeilen[0][2]);
    }

    public function test_the_page_hands_out_a_file(): void
    {
        $seite = Livewire::test(AudiencePage::class)->instance();

        $this->assertInstanceOf(BinaryFileResponse::class, $seite->downloadBuyers('csv'));
        $this->assertInstanceOf(BinaryFileResponse::class, $seite->downloadBuyers('xlsx'));
        $this->assertInstanceOf(BinaryFileResponse::class, $seite->downloadOverlap());
    }

    public function test_the_download_respects_the_current_selection(): void
    {
        $export = new AudienceBuyersExport(
            new \App\Services\Audience\AudienceQuery(eventSlugs: ['sommerfest']),
        );

        $this->assertSame('Sommerfest', $export->array()[0][2]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AudienceExportTest`
Expected: FAIL mit „Class App\Exports\AudienceBuyersExport does not exist".

- [ ] **Step 3: Write the buyer export**

Create `app/Exports/AudienceBuyersExport.php`:

```php
<?php

namespace App\Exports;

use App\Services\Audience\AudienceBuyers;
use App\Services\Audience\AudienceQuery;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The buyer list as a file.
 *
 * BUILT AS AN ARRAY and not streamed from the query, because the event names need a
 * second lookup per buyer - and at the size of this list (a few thousand people at
 * most) the whole thing fits in memory comfortably.
 */
class AudienceBuyersExport implements FromArray, WithHeadings
{
    public function __construct(private readonly AudienceQuery $query) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['E-Mail', 'Name', 'Veranstaltungen', 'Bestellungen', 'Tickets', 'Umsatz', 'Erste Bestellung', 'Letzte Bestellung'];
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        $dienst = app(AudienceBuyers::class);
        $zeilen = [];

        foreach ($dienst->query($this->query)->orderByDesc('tickets')->get() as $kaeufer) {
            $zeilen[] = [
                (string) $kaeufer->buyer_email,
                (string) ($kaeufer->buyer_display_name ?? ''),
                implode(', ', $dienst->eventNames($this->query, (string) $kaeufer->buyer_email)),
                (int) $kaeufer->orders,
                (int) $kaeufer->tickets,
                round((float) $kaeufer->revenue, 2),
                $kaeufer->first_at ? \Illuminate\Support\Carbon::parse($kaeufer->first_at)->format('d.m.Y') : '',
                $kaeufer->last_at ? \Illuminate\Support\Carbon::parse($kaeufer->last_at)->format('d.m.Y') : '',
            ];
        }

        return $zeilen;
    }
}
```

- [ ] **Step 4: Write the overlap export**

Create `app/Exports/AudienceOverlapExport.php`:

```php
<?php

namespace App\Exports;

use App\Models\Event;
use App\Services\Audience\AudienceOverlap;
use App\Services\Audience\AudienceQuery;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The overlap matrix as a file: one row per event, one column per event.
 *
 * The counts travel, the percentages do not - a spreadsheet can divide, and two
 * numbers per cell would make the file unusable for exactly that.
 */
class AudienceOverlapExport implements FromArray, WithHeadings
{
    /** @var array{events: array<int, string>, rows: array<string, array{buyers: int, shared: array<string, array{count: int, share: float}>}>} */
    private array $matrix;

    /** @var array<string, string> */
    private array $namen;

    public function __construct(AudienceQuery $query)
    {
        $this->matrix = app(AudienceOverlap::class)->matrix($query);
        $this->namen = Event::namesBySlug();
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return array_merge(
            ['Veranstaltung', 'Käufer'],
            array_map(fn (string $slug) => $this->label($slug), $this->matrix['events']),
        );
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        $zeilen = [];

        foreach ($this->matrix['rows'] as $slug => $zeile) {
            $werte = [$this->label($slug), $zeile['buyers']];

            foreach ($this->matrix['events'] as $spalte) {
                $werte[] = $zeile['shared'][$spalte]['count'];
            }

            $zeilen[] = $werte;
        }

        return $zeilen;
    }

    private function label(string $slug): string
    {
        return $this->namen[$slug] ?? $slug;
    }
}
```

- [ ] **Step 5: Add the actions to the page**

In `app/Filament/Pages/AudiencePage.php`, in der `table()`-Methode vor `->emptyStateHeading(...)` einfügen:

```php
            ->headerActions([
                \Filament\Tables\Actions\Action::make('kaeuferliste_csv')
                    ->label('Käuferliste als CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => $this->downloadBuyers('csv')),

                \Filament\Tables\Actions\Action::make('kaeuferliste_xlsx')
                    ->label('Käuferliste als Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn () => $this->downloadBuyers('xlsx')),

                \Filament\Tables\Actions\Action::make('ueberschneidung_xlsx')
                    ->label('Überschneidung als Excel')
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->action(fn () => $this->downloadOverlap()),
            ])
```

Und am Ende der Klasse:

```php
    public function downloadBuyers(string $format): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $dateiname = 'publikum-kaeufer-' . now()->format('Y-m-d') . '.' . $format;

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\AudienceBuyersExport($this->currentQuery()),
            $dateiname,
            $format === 'csv'
                ? \Maatwebsite\Excel\Excel::CSV
                : \Maatwebsite\Excel\Excel::XLSX,
        );
    }

    public function downloadOverlap(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\AudienceOverlapExport($this->currentQuery()),
            'publikum-ueberschneidung-' . now()->format('Y-m-d') . '.xlsx',
            \Maatwebsite\Excel\Excel::XLSX,
        );
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AudienceExportTest`
Expected: PASS, 4 Tests.

- [ ] **Step 7: Commit**

```bash
git add app/Exports/AudienceBuyersExport.php app/Exports/AudienceOverlapExport.php app/Filament/Pages/AudiencePage.php tests/Feature/Audience/AudienceExportTest.php
git commit -m "Kaeuferliste und Ueberschneidung als CSV und Excel"
```

---

### Task 14: Gegenprobe auf PostgreSQL, Dokumentation, Release, Erstaufbau

**Files:**
- Modify: `config/version.php`
- Modify: `CHANGELOG.md`
- Modify: `README.md`
- Create: `docs/datenschutz-publikumsauswertung.md`

**Interfaces:**
- Consumes: alles aus Task 1 bis 13
- Produces: Version `0.68.0`, Tag `v0.68.0`, gefüllte Tabelle auf Produktion

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test`
Expected: PASS. Bei roten Bestandstests: Ursache beheben, bevor es weitergeht — ein neuer Bereich darf keinen bestehenden brechen.

- [ ] **Step 2: Cross-check the text search on PostgreSQL**

Die Käufersuche nutzt `LOWER(...) LIKE`. SQLite findet auch ohne `LOWER` alles, PostgreSQL nicht — deshalb wird das gegen den Produktivbestand geprüft, in einer zurückgerollten Transaktion.

Prüfskript lokal schreiben als `/tmp/audience_probe.php`:

```php
<?php

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Audience\AudienceBuyers;
use App\Services\Audience\AudienceQuery;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();

try {
    $dienst = app(AudienceBuyers::class);
    $alle = $dienst->query(new AudienceQuery())->get();
    echo 'Kaeufer gesamt: ' . $alle->count() . "\n";

    $beispiel = $alle->firstWhere(fn ($k) => filled($k->buyer_display_name));

    if ($beispiel === null) {
        echo "Kein Kaeufer mit Namen im Bestand - Suche nicht pruefbar.\n";
    } else {
        $wort = mb_strtoupper(mb_substr(trim($beispiel->buyer_display_name), 0, 4));
        $treffer = AudienceBuyers::search($dienst->query(new AudienceQuery()), $wort)->get();
        echo "Suche nach '{$wort}': " . $treffer->count() . " Treffer\n";
        echo $treffer->count() > 0 ? "OK\n" : "FEHLER: Grossschreibung findet nichts\n";
    }
} finally {
    DB::rollBack();
}
```

Ausführen:

```bash
scp /tmp/audience_probe.php swayy:/tmp/ && ssh swayy 'docker cp /tmp/audience_probe.php paypal-txwatch-app-1:/tmp/probe.php && docker exec paypal-txwatch-app-1 php /tmp/probe.php; docker exec -u 0 paypal-txwatch-app-1 rm -f /tmp/probe.php; rm -f /tmp/audience_probe.php'
```

Erwartet: „OK". Bei „FEHLER" fehlt irgendwo ein `LOWER()` — beheben, dann erneut prüfen.

- [ ] **Step 3: Write the documentation**

In `README.md` im Abschnitt der Funktionen ergänzen:

```markdown
### Publikum

Die Seite **Berichte → Publikum** beantwortet, welche Ticketkäufer sich für welche
Veranstaltungen interessieren. Mehrere Veranstaltungen auswählen, und darunter stehen:
Überschneidung der Veranstaltungen, Erstkäufer gegen wiederkehrende Käufer, die Käuferliste mit
Bestellungen, Tickets und Umsatz, sowie Ticketarten, Vorlaufzeit, Gruppengröße, Verkaufsverlauf,
Bestellwert, Gutscheine, Zahlungsart, Herkunft und Stornoquote.

Die Auswertung liest aus `pretix_positions`, einer abgeleiteten Tabelle, die der pretix-Import
mitschreibt. Nach einem Update wird sie einmal aufgebaut:

    php artisan pretix:rebuild-positions

Der Befehl ist wiederholbar und lässt sich mit `--event=<slug>` auf eine Veranstaltung
beschränken.

Ein Veranstalter mit Portalzugang sieht ausschließlich die eigenen Veranstaltungen.
```

- [ ] **Step 4: Write the entry for the record of processing activities**

Create `docs/datenschutz-publikumsauswertung.md`:

```markdown
# Publikumsauswertung — Eintrag für das Verarbeitungsverzeichnis

Diese Datei hält fest, was für das Verarbeitungsverzeichnis nach Art. 30 DSGVO zu
übernehmen ist. Sie ersetzt das Verzeichnis selbst nicht.

| Punkt | Inhalt |
|---|---|
| Bezeichnung | Publikumsauswertung in PayPal TxWatch |
| Zweck | Verbesserung des Veranstaltungsangebots und der Eventauswahl |
| Rechtsgrundlage | Berechtigtes Interesse, Art. 6 Abs. 1 lit. f DSGVO |
| Betroffene | Ticketkäufer der über pretix verkauften Veranstaltungen |
| Datenkategorien | E-Mail-Adresse, Name, Rechnungsort und PLZ, Ticketart, Preis, Kaufzeitpunkt, Gutscheinkennung, Name auf dem Ticket |
| Herkunft | pretix-Bestellungen, übernommen durch den bestehenden Import |
| Speicherort | Tabelle `pretix_positions` in der TxWatch-Datenbank |
| Empfänger | Betreiber; Veranstalter mit Portalzugang ausschließlich für die eigenen Veranstaltungen |
| Übermittlung in Drittländer | keine |
| Aufbewahrung | Abgeleitet aus pretix. Die Zeile verschwindet, sobald die Bestellung in pretix entfällt und der Import oder `pretix:rebuild-positions` gelaufen ist |
| Auskunft und Löschung | Werden in pretix bedient; TxWatch zieht beim nächsten Lauf nach |
| Technische Maßnahmen | Zugriff nur mit dem Recht `view-audience`; Mandantentrennung über `AudienceQuery` und `CustomerScope::byEventSlug` |
```

- [ ] **Step 5: Bump the version and write the changelog**

In `config/version.php` die Nummer auf `0.68.0` setzen — ein neuer Bereich ist eine neue Fähigkeit, also MINOR.

In `CHANGELOG.md` über dem Eintrag `## [0.67.1]` einfügen:

```markdown
## [0.68.0] - 2026-09-21

### Hinzugefügt
- **Neue Seite „Publikum": wer kommt zu welchen Veranstaltungen.** Mehrere Veranstaltungen
  auswählen, und die Seite zeigt, wie viele Käufer sie sich teilen, wer zum ersten Mal da ist und
  wer wiederkommt. Darunter die Käuferliste mit Bestellungen, Tickets und Umsatz je Person,
  sortierbar und als CSV oder Excel herunterladbar.
- **Elf weitere Auswertungen aus demselben Bestand:** Ticketarten samt der Frage, ob jemand
  überall dieselbe Kategorie wählt, Vorlaufzeit bis zum Veranstaltungstag, Tickets je Bestellung,
  Verkaufsverlauf nach Tag, Wochentag und Tageszeit, Bestellwert mit Median, Gutscheinanteil,
  Zahlungsart, Herkunft nach PLZ-Region und Ort, Storno- und Ablaufquote sowie die Antworten auf
  die Fragen, die pretix beim Kauf stellt.
- **Befehl `pretix:rebuild-positions`** baut die zugrunde liegende Tabelle aus den gespeicherten
  Bestellungen neu auf.

### Geändert
- Der pretix-Import schreibt zu jeder Bestellung die aktiven Ticketpositionen mit. Eine in pretix
  stornierte Position verschwindet beim nächsten Import aus der Auswertung.

### Sicherheit
- Ein Veranstalter mit Portalzugang sieht in der Publikumsauswertung ausschließlich die eigenen
  Veranstaltungen. Die Sperre sitzt in `AudienceQuery`, durch die jede Zahl der Seite läuft.
```

- [ ] **Step 6: Commit, tag and push**

```bash
git add -A
git commit -m "Publikumsauswertung ueber mehrere Veranstaltungen (v0.68.0)"
git push origin main
git tag v0.68.0
git push origin v0.68.0
```

- [ ] **Step 7: Deploy and fill the table**

Erst nach grünem `main`-Lauf in GitHub Actions — `latest` wird nur vom Standardbranch gepusht, ein grüner Tag-Lauf allein aktualisiert das Abbild nicht.

```bash
ssh swayy 'cd /opt/paypal-txwatch && docker compose pull app queue scheduler && docker compose up -d'
ssh swayy 'docker exec paypal-txwatch-app-1 php artisan migrate --force'
ssh swayy 'docker exec paypal-txwatch-app-1 php artisan db:seed --class=RolesAndPermissionsSeeder --force'
ssh swayy 'docker exec paypal-txwatch-app-1 php artisan pretix:rebuild-positions'
```

Erwartet beim letzten Befehl: „1458 Bestellungen gelesen, 3359 Positionen geschrieben" in der Größenordnung des Befunds vom 20.09.2026.

Auf Watchtower wird nicht gewartet: nach jedem Release wird von Hand ausgerollt.

- [ ] **Step 8: Check it from outside**

`https://report.hsp-tickets.de/admin/publikum` aufrufen und prüfen:

- Die Kopfzahlen nennen rund 1259 Käufer und 3359 Tickets.
- Die Überschneidungsmatrix zeigt vier Veranstaltungen, und `gag-wismar-2026` teilt sich Käufer mit `gag-wismar-2027`.
- Die Käuferliste lässt sich nach Tickets sortieren und nach einem Namen durchsuchen.
- Der CSV-Download öffnet sich mit Kopfzeile.

- [ ] **Step 9: Write the server log entry**

Eintrag in `C:\Users\brigh\Claude Workingdir\Serverprotokolle\mailprobe.bcsrv.de.md` ergänzen, oben unter dem Steckbrief: Datum und Uhrzeit vom Server (`TZ=Europe/Berlin date`), was ausgerollt wurde, welche Befehle liefen, das Ergebnis des Erstaufbaus und die Prüfungen von außen.

---

## Nach dem Plan

Der Bestand hat heute 48 Personen mit mehr als einer Veranstaltung. Die Auswertung ist damit
richtig, aber schmal. Sobald `gag-wismar-2027` verkauft ist, lohnt ein zweiter Blick auf die
Überschneidung — und erst dann die Frage, ob Segmente oder ein Verteiler je Segment dazukommen
sollen.
