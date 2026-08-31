<?php

namespace Tests\Feature;

use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionSearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A search result says WHY it is a result.
 *
 * Half the searched fields are columns hidden by default, and the full-text filter
 * also searches the subject, which is no column at all - so a row could appear with
 * nothing on screen containing the term.
 */
class TransactionSearchExplainTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('admin'));

        return $user;
    }

    private function tx(array $overrides = []): Transaction
    {
        static $n = 0;
        $n++;

        // Built the way TransactionFiltersTest does it - raw_payload, raw_hash and
        // imported_at are NOT NULL, and discovering that one error message at a time
        // is how the first version of this fixture went.
        return Transaction::create(array_merge([
            'transaction_id' => 'TX' . $n,
            'dedupe_key' => hash('sha256', 'd' . $n),
            'transaction_initiation_date' => '2026-07-05 12:00:00',
            'gross_amount' => 25.00,
            'net_amount' => 25.00,
            'currency' => 'EUR',
            'raw_payload' => [],
            'raw_hash' => hash('sha256', 'f' . $n),
            'imported_at' => now(),
        ], $overrides));
    }

    /** A name hit names the field and shows the name in full. */
    public function test_a_name_hit_is_explained_with_the_full_name(): void
    {
        $tx = $this->tx(['payer_name' => 'Diana Voß']);

        $treffer = TransactionSearch::explain($tx, 'voß');

        $this->assertSame([['label' => 'Name', 'value' => 'Diana Voß']], $treffer);
    }

    /**
     * LOWER CASE FINDS THE CAPITALISED NAME.
     *
     * Nobody types a surname with a capital letter into a search box. The explanation
     * has to work the same way the search does, or it stays silent on a row it
     * returned.
     */
    public function test_the_explanation_ignores_case(): void
    {
        $tx = $this->tx(['payer_name' => 'Diana Voß']);

        $this->assertNotSame([], TransactionSearch::explain($tx, 'voß'), 'klein geschrieben');
        $this->assertNotSame([], TransactionSearch::explain($tx, 'VOß'), 'gross geschrieben');
        $this->assertNotSame([], TransactionSearch::explain($tx, 'Voß'), 'wie im Feld geschrieben');
        $this->assertSame([], TransactionSearch::explain($tx, 'Meier'), 'ein anderer Name darf nicht treffen');
    }

    /**
     * A HIT IN A HIDDEN FIELD IS THE WHOLE POINT.
     *
     * The e-mail column is off by default: without this the row appears with no
     * visible reason at all.
     */
    public function test_a_hit_in_a_hidden_field_is_explained(): void
    {
        $tx = $this->tx(['payer_name' => 'Jan Meier', 'payer_email' => 'j.voss@example.de']);

        $treffer = TransactionSearch::explain($tx, 'voss');

        $this->assertCount(1, $treffer);
        $this->assertSame('E-Mail', $treffer[0]['label']);
        $this->assertSame('j.voss@example.de', $treffer[0]['value']);
    }

    /** Several fields matching are all listed - one reason is not the whole reason. */
    public function test_every_matching_field_is_listed(): void
    {
        $tx = $this->tx([
            'payer_name' => 'Diana Voß',
            'payer_email' => 'voss@example.de',
            'subject' => 'Zahlung von Voß',
        ]);

        $labels = array_column(TransactionSearch::explain($tx, 'voß'), 'label');

        $this->assertContains('Name', $labels);
        $this->assertContains('Betreff', $labels);
        // The e-mail spells it "voss" and must NOT be claimed as a hit for "voß".
        $this->assertNotContains('E-Mail', $labels);
    }

    /**
     * ONLY SEARCHED FIELDS ARE EXPLAINED.
     *
     * The PayPal payload carries a delivery name, but nothing searches it. Explaining
     * it would suggest the search reaches further than it does - the field could only
     * ever appear next to another hit and never produce one.
     */
    public function test_unsearched_fields_are_not_explained(): void
    {
        $tx = $this->tx([
            'payer_name' => 'Jan Meier',
            'raw_payload' => ['shipping_info' => ['name' => 'Diana, Voß']],
        ]);

        $this->assertSame([], TransactionSearch::explain($tx, 'voß'));
    }

    /** Without a term there is nothing to explain. */
    public function test_no_term_means_no_explanation(): void
    {
        $tx = $this->tx(['payer_name' => 'Diana Voß']);

        $this->assertSame([], TransactionSearch::explain($tx, null));
        $this->assertSame([], TransactionSearch::explain($tx, '   '));
    }

    /** The column appears only while something is being searched. */
    public function test_the_column_shows_up_only_while_searching(): void
    {
        $this->actingAs($this->admin());
        // The subject matches too, and it is no column at all - exactly the case
        // this column exists for.
        $tx = $this->tx(['payer_name' => 'Diana Voß', 'subject' => 'Überweisung Voß']);

        $seite = Livewire::test(ListTransactions::class)->assertOk();

        // Asked of the column itself, not of the HTML: the header also appears in the
        // column-toggle menu, so searching the markup for it proves nothing.
        $sichtbar = fn ($seite) => ! collect($seite->instance()->getTable()->getColumns())
            ->first(fn ($c) => $c->getName() === 'treffer')?->isHidden();

        $this->assertFalse($sichtbar($seite), 'Ohne Suchbegriff hat die Spalte nichts zu sagen.');

        $seite->set('tableSearch', 'voß');

        $this->assertTrue($sichtbar($seite));

        // loadTable() FIRST: this table uses deferLoading(), so the body is not in
        // the markup until it is asked for - without this every assertion about a row
        // fails while the page is perfectly fine.
        $seite->loadTable()
            ->assertCanSeeTableRecords([$tx])
            // The reason in full, so the person is recognisable - and the hidden
            // e-mail column is named too.
            ->assertTableColumnStateSet('treffer', 'Name: Diana Voß · Betreff: Überweisung Voß', $tx);
    }

    /** The term is read from the full-text filter too, not just the search box. */
    public function test_the_term_is_also_taken_from_the_filter(): void
    {
        $this->actingAs($this->admin());
        $this->tx(['payer_name' => 'Diana Voß']);

        $page = Livewire::test(ListTransactions::class)
            ->filterTable('custom_field_search', ['value' => 'voß', 'field' => 'all', 'mode' => 'contains']);

        $this->assertSame('voß', TransactionSearch::term($page->instance()));
    }
}
