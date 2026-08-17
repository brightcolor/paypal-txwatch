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

        // T1 (open) plus both halves of the double payment - not T3, not T4.
        $this->assertSame('3', EnableBankingJournalResource::getNavigationBadge());
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
}
