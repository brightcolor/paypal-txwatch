<?php

namespace Tests\Feature\Pretix;

use App\Filament\Pages\TicketStatsPage;
use App\Models\ErrorLogEntry;
use App\Models\PretixConnection;
use App\Models\User;
use App\Services\Pretix\PretixUnavailable;
use Database\Seeders\RolesAndPermissionsSeeder;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The ticket statistics page keeps rendering when pretix fails: it names the
 * connection, what happened and what to do, logs the failure once and caches
 * nothing.
 */
class TicketStatsPageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'tok-4711-geheim';

    private PretixConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);

        $this->connection = PretixConnection::create([
            'name' => 'Vereinskasse', 'base_url' => 'https://pretix.example.test', 'organizer_slug' => 'verein',
            'api_token' => self::TOKEN, 'is_active' => true,
        ]);
    }

    public function test_unreachable_pretix_shows_a_message_and_the_page_still_renders(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->get(TicketStatsPage::getUrl())
            ->assertOk()
            ->assertSee('pretix-Verbindung „Vereinskasse“: pretix ist nicht erreichbar.')
            ->assertSee('Später erneut versuchen. Hält das an, unter „pretix-Verbindungen“ die Basis-URL prüfen.')
            ->assertDontSee('cURL error')
            ->assertDontSee('GuzzleHttp')
            ->assertDontSee(self::TOKEN);

        $this->assertNothingCached();
        $this->assertLoggedOnce('cURL error 6');
    }

    public function test_a_timeout_says_that_pretix_did_not_answer_in_time(): void
    {
        // The exception Guzzle's cURL handler raises when the timeout runs out.
        Http::fake(['*' => fn (Request $request) => Create::rejectionFor(new ConnectException(
            'cURL error 28: Operation timed out after 20002 milliseconds with 0 bytes received '
            . '(see https://curl.se/libcurl/c/libcurl-errors.html) for ' . $request->url(),
            $request->toPsrRequest(),
            null,
            ['errno' => 28],
        ))]);

        $this->get(TicketStatsPage::getUrl())
            ->assertOk()
            ->assertSee('pretix-Verbindung „Vereinskasse“: pretix hat nicht rechtzeitig geantwortet.')
            ->assertSee('Später erneut versuchen.')
            ->assertDontSee('cURL error');

        $this->assertNothingCached();
        $this->assertLoggedOnce('cURL error 28');
    }

    #[DataProvider('deniedStatuses')]
    public function test_a_rejected_token_points_to_the_connection_settings(int $status): void
    {
        Http::fake(['*' => Http::response(['detail' => 'Invalid token.'], $status)]);

        $this->get(TicketStatsPage::getUrl())
            ->assertOk()
            ->assertSee("pretix-Verbindung „Vereinskasse“: pretix hat den Zugang abgelehnt (HTTP {$status}).")
            ->assertSee('Unter „pretix-Verbindungen“ das API-Token prüfen und bei Bedarf ein neues eintragen.')
            ->assertDontSee(self::TOKEN);

        $this->assertNothingCached();
        $this->assertLoggedOnce("status code {$status}");
    }

    /** @return array<string, array{int}> */
    public static function deniedStatuses(): array
    {
        return ['Token ungültig' => [401], 'Token ohne Recht' => [403]];
    }

    public function test_a_pretix_server_error_asks_to_try_again_later(): void
    {
        Http::fake(['*' => Http::response('<h1>Server Error</h1>', 500)]);

        $this->get(TicketStatsPage::getUrl())
            ->assertOk()
            ->assertSee('pretix-Verbindung „Vereinskasse“: pretix meldet einen Fehler (HTTP 500).')
            ->assertSee('Später erneut versuchen.');

        $this->assertNothingCached();
        $this->assertLoggedOnce('status code 500');
    }

    public function test_a_failed_quota_call_is_neither_shown_as_zero_nor_cached(): void
    {
        Http::fake([
            '*/quotas/*' => Http::response(['detail' => 'Service unavailable'], 503),
            '*/events/*' => Http::response([
                'results' => [['slug' => 'sommerfest', 'name' => ['de' => 'Sommerfest']]],
                'next' => null,
            ]),
        ]);

        $this->get(TicketStatsPage::getUrl())
            ->assertOk()
            ->assertSee('pretix-Verbindung „Vereinskasse“: pretix meldet einen Fehler (HTTP 503).')
            ->assertDontSee('Sommerfest');

        $this->assertNothingCached();
        $this->assertLoggedOnce('status code 503');
    }

    public function test_figures_load_and_are_cached(): void
    {
        $this->fakePretixWithFigures();

        $this->get(TicketStatsPage::getUrl())
            ->assertOk()
            ->assertSee('Sommerfest')
            ->assertSee('66,7')
            ->assertDontSee('pretix-Verbindung „Vereinskasse“:');

        $this->assertTrue(Cache::has($this->cacheKey()));
        $this->assertSame(0, ErrorLogEntry::query()->count());
    }

    public function test_a_failed_refresh_shows_the_message_and_leaves_nothing_cached(): void
    {
        // The page first renders from the cache, then "Aktualisieren" meets a pretix that is down.
        Cache::put($this->cacheKey(), collect([[
            'slug' => 'sommerfest', 'name' => 'Sommerfest', 'capacity' => 150, 'sold' => 100,
            'available' => 50, 'unlimited' => false, 'ratio' => 66.7,
        ]]), 600);
        Http::fake(['*' => Http::failedConnection()]);

        Livewire::test(TicketStatsPage::class)
            ->assertSee('Sommerfest')
            ->callAction('refresh')
            ->assertNotified('Aktualisieren fehlgeschlagen')
            ->assertSee('pretix-Verbindung „Vereinskasse“: pretix ist nicht erreichbar.')
            ->assertDontSee('Sommerfest');

        $this->assertNothingCached();

        // Action and view share one call. The error log is covered by the page
        // tests above: Livewire's test harness turns report() into a no-op.
        Http::assertSentCount(1);
    }

    public function test_a_successful_refresh_reloads_and_caches_the_figures(): void
    {
        $this->fakePretixWithFigures();

        Livewire::test(TicketStatsPage::class)
            ->callAction('refresh')
            ->assertNotified('Aktualisiert')
            ->assertSee('Sommerfest');

        $this->assertTrue(Cache::has($this->cacheKey()));
    }

    private function fakePretixWithFigures(): void
    {
        Http::fake([
            // Quotas first (more specific) so it wins over the events pattern.
            '*/quotas/*' => Http::response([
                'results' => [
                    ['id' => 1, 'name' => 'Stehplatz', 'size' => 100, 'available_number' => 40],
                    ['id' => 2, 'name' => 'Sitzplatz', 'size' => 50, 'available_number' => 10],
                ],
                'next' => null,
            ]),
            '*/events/*' => Http::response([
                'results' => [['slug' => 'sommerfest', 'name' => ['de' => 'Sommerfest']]],
                'next' => null,
            ]),
        ]);
    }

    private function cacheKey(): string
    {
        return "pretix_ticket_stats:{$this->connection->id}";
    }

    private function assertNothingCached(): void
    {
        $this->assertFalse(Cache::has($this->cacheKey()), 'Ein Fehlschlag wurde zwischengespeichert.');
    }

    /** One render or refresh asks pretix once and records the failure once. */
    private function assertLoggedOnce(string $cause): void
    {
        $entry = ErrorLogEntry::query()->where('exception_class', PretixUnavailable::class)->sole();

        $this->assertSame(1, $entry->occurrences);
        $this->assertStringContainsString('„Vereinskasse“', $entry->message);
        $this->assertStringContainsString($cause, $entry->message);
        $this->assertStringNotContainsString(self::TOKEN, $entry->message);
    }
}
