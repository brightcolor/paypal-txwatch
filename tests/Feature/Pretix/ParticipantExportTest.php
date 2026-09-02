<?php

namespace Tests\Feature\Pretix;

use App\Filament\Pages\ParticipantExportPage;
use App\Models\PretixConnection;
use App\Models\PretixItem;
use App\Models\PretixOrder;
use App\Models\User;
use App\Services\Pretix\ParticipantExporter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Exporting who bought which ticket type.
 *
 * The question behind it: "all e-mail addresses of one event with VIP or Meet &
 * Greet tickets". The ticket type lives in the order positions as a bare number, so
 * nothing could filter by it before.
 *
 * All names and addresses here are invented.
 */
class ParticipantExportTest extends TestCase
{
    use RefreshDatabase;

    private const VIP = 3;
    private const MEET = 4;
    private const NORMAL = 23;

    private PretixConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein',
            'api_token' => 'tok', 'is_active' => true,
        ]);

        foreach ([self::VIP => 'VIP-Ticket', self::MEET => 'Meet & Greet', self::NORMAL => 'Normalticket'] as $id => $name) {
            PretixItem::create([
                'pretix_connection_id' => $this->connection->id,
                'event_slug' => 'sommerfest',
                'item_id' => $id,
                'name' => $name,
            ]);
        }
    }

    /**
     * @param  array<int, array{item: int, price?: float, name?: string, canceled?: bool}>  $positionen
     */
    private function order(string $code, string $email, array $positionen, string $status = 'p'): PretixOrder
    {
        return PretixOrder::create([
            'pretix_connection_id' => $this->connection->id,
            'event_slug' => 'sommerfest',
            'order_code' => $code,
            'status' => $status,
            'payment_provider' => 'banktransfer',
            'total' => 100.00,
            'currency' => 'EUR',
            'email' => $email,
            'order_datetime' => '2026-07-05 12:00:00',
            'url' => 'https://pretix.eu/x/' . $code,
            'raw_payload' => [
                'invoice_address' => ['name' => 'Max Mustermann'],
                'positions' => array_map(fn (array $p) => [
                    'item' => $p['item'],
                    'price' => $p['price'] ?? 50.0,
                    'attendee_name' => $p['name'] ?? null,
                    'canceled' => $p['canceled'] ?? false,
                ], $positionen),
            ],
        ]);
    }

    /**
     * THE ASKED-FOR CASE: addresses of everyone holding VIP or Meet & Greet.
     *
     * The holder of a plain ticket must not be in it - that is the whole filter.
     */
    public function test_addresses_of_two_chosen_ticket_types(): void
    {
        $this->order('VIPA1', 'anna@example.de', [['item' => self::VIP]]);
        $this->order('MEET1', 'bernd@example.de', [['item' => self::MEET]]);
        $this->order('NORM1', 'clara@example.de', [['item' => self::NORMAL]]);

        $gebaut = app(ParticipantExporter::class)->build('sommerfest', [self::VIP, self::MEET]);

        $mails = array_column($gebaut['rows'], 0);
        sort($mails);

        $this->assertSame(['anna@example.de', 'bernd@example.de'], $mails);
        $this->assertSame(2, $gebaut['count']);
    }

    /**
     * ONE PERSON IS ONE ROW, even with several tickets and several orders.
     *
     * A mailing list with the same address three times sends three mails.
     */
    public function test_a_person_appears_once_with_everything_they_hold(): void
    {
        $this->order('ORD01', 'anna@example.de', [['item' => self::VIP], ['item' => self::VIP]]);
        // Same mailbox, different spelling - pretix stores what the buyer typed.
        $this->order('ORD02', 'Anna@Example.de', [['item' => self::MEET]]);

        $gebaut = app(ParticipantExporter::class)->build('sommerfest', [self::VIP, self::MEET]);

        $this->assertSame(1, $gebaut['count']);

        [$mail, $name, $arten, $tickets, $bestellungen] = $gebaut['rows'][0];

        $this->assertSame('anna@example.de', $mail);
        $this->assertSame('Max Mustermann', $name);
        $this->assertStringContainsString('VIP-Ticket', $arten);
        $this->assertStringContainsString('Meet & Greet', $arten);
        $this->assertSame(3, $tickets, 'Drei Tickets, aber eine Person.');
        $this->assertStringContainsString('ORD01', $bestellungen);
        $this->assertStringContainsString('ORD02', $bestellungen);
    }

    /** Purchases mode gives one row per ticket - three tickets are three rows. */
    public function test_purchases_give_one_row_per_ticket(): void
    {
        $this->order('ORD01', 'anna@example.de', [
            ['item' => self::VIP, 'name' => 'Anna Beispiel'],
            ['item' => self::VIP, 'name' => 'Bea Beispiel'],
        ]);

        $gebaut = app(ParticipantExporter::class)->build(
            'sommerfest', [self::VIP], ['p'], ParticipantExporter::MODE_PURCHASES,
        );

        $this->assertSame(2, $gebaut['count']);
        $this->assertContains('Name auf dem Ticket', $gebaut['headings']);
        // The name on the ticket can differ from the buyer - for a guest list that
        // difference is the point.
        $this->assertSame('Anna Beispiel', $gebaut['rows'][0][4]);
        $this->assertSame('Bea Beispiel', $gebaut['rows'][1][4]);
        $this->assertSame('VIP-Ticket', $gebaut['rows'][0][5]);
    }

    /** Choosing no ticket type means all of them. */
    public function test_no_selection_means_all_ticket_types(): void
    {
        $this->order('ORD01', 'anna@example.de', [['item' => self::VIP]]);
        $this->order('ORD02', 'clara@example.de', [['item' => self::NORMAL]]);

        $this->assertSame(2, app(ParticipantExporter::class)->build('sommerfest')['count']);
    }

    /**
     * A CANCELLED POSITION IS NOT A GUEST.
     *
     * pretix keeps it in the payload with `canceled: true`; counting it would put
     * someone on a door list who will not come.
     */
    public function test_a_cancelled_position_is_left_out(): void
    {
        $this->order('ORD01', 'anna@example.de', [
            ['item' => self::VIP, 'canceled' => true],
            ['item' => self::VIP],
        ]);

        $gebaut = app(ParticipantExporter::class)->build('sommerfest', [self::VIP]);

        $this->assertSame(1, $gebaut['rows'][0][3], 'Nur das nicht stornierte Ticket zählt.');
    }

    /** Only orders whose status was chosen - paid by default. */
    public function test_the_status_filter_holds(): void
    {
        $this->order('PAID1', 'anna@example.de', [['item' => self::VIP]], 'p');
        $this->order('OPEN1', 'bernd@example.de', [['item' => self::VIP]], 'n');
        $this->order('CANC1', 'clara@example.de', [['item' => self::VIP]], 'c');

        $nurBezahlt = app(ParticipantExporter::class)->build('sommerfest', [self::VIP], ['p']);
        $this->assertSame(['anna@example.de'], array_column($nurBezahlt['rows'], 0));

        $auchOffen = app(ParticipantExporter::class)->build('sommerfest', [self::VIP], ['p', 'n']);
        $this->assertSame(2, $auchOffen['count']);
    }

    /** An order without an e-mail cannot be written to and is left out of the list. */
    public function test_an_order_without_an_email_is_skipped(): void
    {
        $this->order('NOMAIL', '', [['item' => self::VIP]]);

        $this->assertSame(0, app(ParticipantExporter::class)->build('sommerfest', [self::VIP])['count']);
    }

    /** An unknown ticket type is named by its number rather than dropped. */
    public function test_an_unknown_ticket_type_is_still_named(): void
    {
        $this->order('ORD01', 'anna@example.de', [['item' => 999]]);

        $gebaut = app(ParticipantExporter::class)->build('sommerfest');

        $this->assertStringContainsString('Ticketart #999', $gebaut['rows'][0][2]);
    }

    /** The page renders, offers the event's ticket types and previews the count. */
    public function test_the_page_offers_the_ticket_types_and_previews(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);

        \App\Models\Event::create([
            'name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'is_active' => true,
        ]);
        $this->order('VIPA1', 'anna@example.de', [['item' => self::VIP]]);

        Livewire::test(ParticipantExportPage::class)
            ->assertOk()
            // The warning about personal data belongs above the form, not behind the
            // button.
            ->assertSee('Personenbezogene Daten')
            ->fillForm(['event_slug' => 'sommerfest', 'item_ids' => [self::VIP]])
            ->assertSee('VIP-Ticket')
            // The count BEFORE the download - a mailing list gets acted on.
            ->assertSee('1 E-Mail-Adressen');
    }

    /** An empty result is refused rather than delivered as an empty file. */
    public function test_an_empty_selection_is_refused(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);

        \App\Models\Event::create([
            'name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'is_active' => true,
        ]);
        $this->order('NORM1', 'clara@example.de', [['item' => self::NORMAL]]);

        Livewire::test(ParticipantExportPage::class)
            ->fillForm(['event_slug' => 'sommerfest', 'item_ids' => [self::VIP]])
            ->call('export')
            ->assertNotified();

        $this->assertSame(0, app(ParticipantExporter::class)->build('sommerfest', [self::VIP])['count']);
    }
    /**
     * ONLY THE CHOSEN COLUMNS, IN THE CHOSEN ORDER.
     *
     * The order is half the point: a list that gets pasted somewhere needs the
     * address first, and a multi-select cannot express that.
     */
    public function test_only_the_chosen_columns_in_the_chosen_order(): void
    {
        $this->order('ORD01', 'anna@example.de', [['item' => self::VIP]]);

        $gebaut = app(ParticipantExporter::class)->build(
            'sommerfest', [self::VIP], ['p'], ParticipantExporter::MODE_ADDRESSES,
            ['name', 'email'],
        );

        $this->assertSame(['Name', 'E-Mail'], $gebaut['headings']);
        $this->assertSame(['Max Mustermann', 'anna@example.de'], $gebaut['rows'][0]);
    }

    /** A single column is allowed - the address list for a mail client. */
    public function test_a_single_column_is_allowed(): void
    {
        $this->order('ORD01', 'anna@example.de', [['item' => self::VIP]]);

        $gebaut = app(ParticipantExporter::class)->build(
            'sommerfest', [], ['p'], ParticipantExporter::MODE_ADDRESSES, ['email'],
        );

        $this->assertSame(['E-Mail'], $gebaut['headings']);
        $this->assertSame([['anna@example.de']], $gebaut['rows']);
    }

    /**
     * CHOOSING COLUMNS NEVER CHANGES WHICH ROWS COME OUT.
     *
     * Rows are built complete and projected afterwards. If narrowing the columns
     * could drop people, an address list would silently lose recipients.
     */
    public function test_the_column_choice_does_not_change_the_row_count(): void
    {
        $this->order('ORD01', 'anna@example.de', [['item' => self::VIP]]);
        $this->order('ORD02', 'bernd@example.de', [['item' => self::VIP]]);

        $alle = app(ParticipantExporter::class)->build('sommerfest', [self::VIP]);
        $eine = app(ParticipantExporter::class)->build(
            'sommerfest', [self::VIP], ['p'], ParticipantExporter::MODE_ADDRESSES, ['email'],
        );

        $this->assertSame($alle['count'], $eine['count']);
    }

    /** An unknown column key is dropped rather than exported as a nameless column. */
    public function test_an_unknown_column_is_dropped(): void
    {
        $this->order('ORD01', 'anna@example.de', [['item' => self::VIP]]);

        $gebaut = app(ParticipantExporter::class)->build(
            'sommerfest', [], ['p'], ParticipantExporter::MODE_ADDRESSES,
            ['email', 'gibtesnicht', 'preis'],
        );

        $this->assertSame(['E-Mail'], $gebaut['headings']);
    }

    /** No column chosen means all of them, in catalogue order. */
    public function test_no_column_chosen_means_all_of_them(): void
    {
        $this->order('ORD01', 'anna@example.de', [['item' => self::VIP]]);

        $gebaut = app(ParticipantExporter::class)->build('sommerfest');

        $this->assertSame(
            array_values(ParticipantExporter::COLUMNS[ParticipantExporter::MODE_ADDRESSES]),
            $gebaut['headings'],
        );
    }

    /**
     * THE TEXT FORMAT IS PLAIN, and with one column it is one value per line.
     *
     * That is the case it exists for: an address list to paste into a mail client,
     * which quoting would ruin.
     */
    public function test_text_with_one_column_is_one_value_per_line(): void
    {
        $this->order('ORD01', 'anna@example.de', [['item' => self::VIP]]);
        $this->order('ORD02', 'bernd@example.de', [['item' => self::VIP]]);

        $text = ParticipantExporter::toText(app(ParticipantExporter::class)->build(
            'sommerfest', [self::VIP], ['p'], ParticipantExporter::MODE_ADDRESSES, ['email'],
        ));

        $this->assertSame("E-Mail\nanna@example.de\nbernd@example.de\n", $text);
    }

    /** Several columns are separated by a tab. */
    public function test_text_separates_columns_with_a_tab(): void
    {
        $this->order('ORD01', 'anna@example.de', [['item' => self::VIP]]);

        $text = ParticipantExporter::toText(app(ParticipantExporter::class)->build(
            'sommerfest', [self::VIP], ['p'], ParticipantExporter::MODE_ADDRESSES, ['email', 'name'],
        ));

        $zeilen = explode("\n", trim($text));

        $this->assertSame("E-Mail\tName", $zeilen[0]);
        $this->assertSame("anna@example.de\tMax Mustermann", $zeilen[1]);
    }

    /**
     * A TAB OR NEWLINE INSIDE A VALUE MUST NOT FORGE A COLUMN OR ROW.
     *
     * pretix stores what the buyer typed, and a pasted name can carry anything.
     */
    public function test_a_tab_inside_a_value_does_not_forge_a_column(): void
    {
        $order = $this->order('ORD01', 'anna@example.de', [['item' => self::VIP]]);
        $order->forceFill(['raw_payload' => array_merge($order->raw_payload, [
            'invoice_address' => ['name' => "Max\tMustermann\nZeile2"],
        ])])->save();

        $text = ParticipantExporter::toText(app(ParticipantExporter::class)->build(
            'sommerfest', [self::VIP], ['p'], ParticipantExporter::MODE_ADDRESSES, ['email', 'name'],
        ));

        $zeilen = explode("\n", trim($text));

        $this->assertCount(2, $zeilen, 'Der Umbruch im Namen darf keine dritte Zeile erzeugen.');
        $this->assertSame("anna@example.de\tMax Mustermann Zeile2", $zeilen[1]);
    }

}
