<?php

namespace Tests\Feature\Bank;

use App\Filament\Resources\EnableBankingJournalResource;
use App\Filament\Resources\EnableBankingJournalResource\Pages\ListEnableBankingJournalEntries;
use App\Models\EnableBankingJournalEntry;
use App\Models\User;
use App\Services\EnableBanking\JournalWriter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The journal TABLE - columns and filters actually executed.
 *
 * Every closure in the resource (state, colour, tooltip, filter query) runs only
 * when a row is rendered or a filter is clicked. Without this test a broken
 * filter query or a column reaching for a missing field would surface in the
 * browser and nowhere else - which is how the last two mistakes in this area
 * came to light.
 */
class EnableBankingJournalTableTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('admin'));

        return $user;
    }

    /**
     * One entry per state - built through the writer, not by hand.
     *
     * The double payment needs TWO credits on one order, which is the actual
     * criterion; a single payment on a settled order is the normal case.
     */
    private function fourStates(): void
    {
        $connection = \App\Models\PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein',
            'api_token' => 'tok', 'is_active' => true,
        ]);

        foreach ([['OPENF', 'n'], ['PAIDF', 'p']] as [$code, $status]) {
            \App\Models\PretixOrder::create([
                'pretix_connection_id' => $connection->id,
                'event_slug' => 'musterevent',
                'order_code' => $code,
                'status' => $status,
                'payment_provider' => 'banktransfer',
                'total' => 63.80,
                'currency' => 'EUR',
                'url' => 'https://pretix.eu/control/order/musterevent/' . $code . '/',
                'raw_payload' => [],
            ]);
        }

        app(JournalWriter::class)->record([
            // T1 open order, T2a+T2b two credits on one order, T3 a proposal one
            // character off, T4 nothing recognisable.
            EnableBankingJournalTest::entry(['amount' => 63.80, 'purpose' => 'GAG-2026-OPENF', 'bank_ref' => 'T1']),
            EnableBankingJournalTest::entry(['amount' => 63.80, 'purpose' => 'Zahlung PAIDF', 'bank_ref' => 'T2a']),
            EnableBankingJournalTest::entry(['amount' => 5.00, 'purpose' => 'Nachzahlung PAIDF', 'bank_ref' => 'T2b']),
            EnableBankingJournalTest::entry(['amount' => 63.80, 'purpose' => 'Zahlung OPENG', 'bank_ref' => 'T3']),
            EnableBankingJournalTest::entry(['amount' => 12.00, 'purpose' => 'PayPal Auszahlung', 'bank_ref' => 'T4']),
        ]);

        $this->assertSame(5, EnableBankingJournalEntry::count(), 'Der Aufbau des Testbestands ist schiefgegangen.');
    }

    /**
     * The table renders with all four states present.
     *
     * Rendering is the assertion: it runs every column closure over every row.
     */
    public function test_the_table_renders_every_state(): void
    {
        $this->actingAs($this->admin());
        $this->fourStates();

        Livewire::test(ListEnableBankingJournalEntries::class)
            ->assertOk()
            ->assertSee('offen – zu buchen')
            ->assertSee('mögliche Doppelzahlung')
            ->assertSee('keine Zuordnung');
    }

    /**
     * THE WORK LIST SHOWS WORK AND NOTHING ELSE.
     *
     * The one filter that has to be right: it is what someone opens the journal for.
     * A settled order in here would mean chasing a payment that arrived long ago.
     */
    public function test_the_work_list_holds_open_orders_and_suggestions_only(): void
    {
        $this->actingAs($this->admin());
        $this->fourStates();

        $codes = Livewire::test(ListEnableBankingJournalEntries::class)
            ->filterTable('zu_tun')
            ->instance()
            ->getFilteredTableQuery()
            ->pluck('bank_ref')
            ->all();

        sort($codes);

        // Open order, both halves of the double payment, and the proposal.
        // NOT T4 - nothing was recognised there and clicking will not change that.
        $this->assertSame(['T1', 'T2a', 'T2b', 'T3'], $codes);
    }

    /** The double-payment filter finds exactly the credit on the settled order. */
    public function test_the_double_payment_filter_finds_it(): void
    {
        $this->actingAs($this->admin());
        $this->fourStates();

        $codes = Livewire::test(ListEnableBankingJournalEntries::class)
            ->filterTable('doppelzahlungen')
            ->instance()
            ->getFilteredTableQuery()
            ->pluck('bank_ref')
            ->all();

        sort($codes);

        $this->assertSame(['T2a', 'T2b'], $codes);
    }

    /**
     * The menu badge counts decisions, not rows.
     *
     * It used to count everything unpromoted, which with 986 of 1025 orders already
     * paid was essentially the table size - a permanent number nobody could act on.
     */
    public function test_the_badge_counts_only_what_needs_a_decision(): void
    {
        $this->fourStates();

        // T1 (open), both halves of the double payment, and the proposal T3.
        // NOT T4 - nothing was recognised there and clicking will not change that.
        $this->assertSame('4', EnableBankingJournalResource::getNavigationBadge());
    }

    /** The remaining filters still execute after the rewrite. */
    public function test_the_other_filters_still_run(): void
    {
        $this->actingAs($this->admin());
        $this->fourStates();

        foreach (['offene_vorschlaege', 'nur_eingaenge'] as $filter) {
            Livewire::test(ListEnableBankingJournalEntries::class)
                ->filterTable($filter)
                ->assertOk();
        }
    }
    /**
     * THE BUTTON SHOWS EXACTLY WHAT THE NUMBER PROMISES.
     *
     * The point of the whole change: badge, filter and button read one scope. They
     * used to carry three variants - the badge left out proposals, the filter left in
     * entries already taken into the books - so a button on the number would have
     * shown a different set than the number claimed. This test is what keeps them
     * from drifting apart again.
     */
    public function test_the_button_shows_exactly_what_the_badge_counts(): void
    {
        $this->actingAs($this->admin());
        $this->fourStates();

        $badge = (int) EnableBankingJournalResource::getNavigationBadge();

        $page = Livewire::test(ListEnableBankingJournalEntries::class)
            ->callAction('zu_entscheiden');

        $gefiltert = $page->instance()->getFilteredTableQuery()->count();

        $this->assertSame($badge, $gefiltert, 'Die Zahl am Menüpunkt und die gefilterte Liste weichen ab.');
        $this->assertGreaterThan(0, $badge, 'Ohne offene Punkte prüft dieser Test nichts.');

        // And it is genuinely a subset, not the whole table.
        $this->assertLessThan(EnableBankingJournalEntry::count(), $gefiltert);
    }

    /** A second click takes the filter off again. */
    public function test_the_button_switches_back_to_everything(): void
    {
        $this->actingAs($this->admin());
        $this->fourStates();

        $page = Livewire::test(ListEnableBankingJournalEntries::class)
            ->callAction('zu_entscheiden')
            ->callAction('zu_entscheiden');

        $this->assertSame(
            EnableBankingJournalEntry::count(),
            $page->instance()->getFilteredTableQuery()->count(),
        );
    }

    /** With nothing to decide there is no button - and no badge either. */
    public function test_without_anything_to_decide_there_is_no_button(): void
    {
        $this->actingAs($this->admin());

        // A single, cleanly settled payment: recognised, nothing to do.
        $connection = \App\Models\PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein',
            'api_token' => 'tok', 'is_active' => true,
        ]);
        \App\Models\PretixOrder::create([
            'pretix_connection_id' => $connection->id,
            'event_slug' => 'musterevent', 'order_code' => 'CLEAN', 'status' => 'p',
            'payment_provider' => 'banktransfer', 'total' => 63.80, 'currency' => 'EUR',
            'url' => 'https://pretix.eu/control/order/musterevent/CLEAN/', 'raw_payload' => [],
        ]);

        app(JournalWriter::class)->record([
            EnableBankingJournalTest::entry(['amount' => 63.80, 'purpose' => 'Zahlung CLEAN', 'bank_ref' => 'N1']),
        ]);

        $this->assertNull(EnableBankingJournalResource::getNavigationBadge());

        // Not "hidden" but absent: with nothing to decide the header stays empty
        // rather than offering a filter that would return no rows.
        Livewire::test(ListEnableBankingJournalEntries::class)
            ->assertOk()
            ->assertActionDoesNotExist('zu_entscheiden');
    }

    /**
     * THE SQL SCOPE AND THE PHP PREDICATE AGREE.
     *
     * `needsDecision()` (SQL, for badge/filter/button) and `isActionable()` (PHP, for
     * the row's own colouring) express one rule twice. Two implementations of one
     * rule drift; this compares them row by row instead of trusting the reading.
     */
    public function test_the_scope_agrees_with_the_model_predicate(): void
    {
        $this->fourStates();

        $ausSql = EnableBankingJournalEntry::query()
            ->needsDecision()
            ->pluck('bank_ref')
            ->sort()
            ->values()
            ->all();

        $ausPhp = EnableBankingJournalEntry::all()
            ->filter(fn ($e) => $e->isActionable() && ! $e->isPromoted())
            ->pluck('bank_ref')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($ausPhp, $ausSql, 'needsDecision() und isActionable() sind auseinandergelaufen.');
    }
}
