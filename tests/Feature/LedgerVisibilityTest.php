<?php

namespace Tests\Feature;

use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LedgerVisibilityTest extends TestCase
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
        static $i = 0;
        $i++;
        $account = PaypalAccount::firstOrCreate(['name' => 'Acc'], ['mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);

        return Transaction::create(array_merge([
            'paypal_account_id' => $account->id,
            'transaction_id' => 'TXN' . $i,
            'transaction_event_code' => 'T0006',
            'gross_amount' => 100,
            'currency' => 'EUR',
            'raw_payload' => [],
            'raw_hash' => hash('sha256', 'h' . $i),
            'dedupe_key' => hash('sha256', 'k' . $i),
            'imported_at' => now(),
        ], $overrides));
    }

    public function test_ledger_rows_are_hidden_from_the_list_but_shown_on_the_payment_detail_page(): void
    {
        $payment = $this->tx(['transaction_id' => 'PAY1', 'custom_field' => 'Order FCASPIEL-QCVSY', 'gross_amount' => 131.84]);
        $hold = $this->tx(['transaction_id' => 'HOLD1', 'transaction_event_code' => 'T2101', 'custom_field' => 'Order FCASPIEL-QCVSY', 'gross_amount' => -29.46]);
        $release = $this->tx(['transaction_id' => 'REL1', 'transaction_event_code' => 'T2102', 'gross_amount' => 29.46, 'paypal_reference_id' => 'PAY1', 'custom_field' => null]);
        $withdrawal = $this->tx(['transaction_id' => 'WD1', 'transaction_event_code' => 'T0400', 'gross_amount' => -500, 'custom_field' => null]);

        $admin = $this->admin();

        // List: payment visible, ledger rows (hold/release/withdrawal) not.
        // (loadTable: the table defers row loading to a follow-up request.)
        $html = Livewire::actingAs($admin)->test(ListTransactions::class)->call('loadTable')->html();
        $this->assertStringContainsString('PAY1', $html);
        $this->assertStringNotContainsString('HOLD1', $html);
        $this->assertStringNotContainsString('WD1', $html);

        // Related ledger movements are found via custom_field AND reference id.
        $related = $payment->relatedLedgerTransactions()->pluck('transaction_id')->all();
        $this->assertEqualsCanonicalizing(['HOLD1', 'REL1'], $related);

        // Detail page of the payment lists them.
        $response = $this->actingAs($admin)->get("/admin/transactions/{$payment->id}");
        $response->assertSuccessful();
        $response->assertSee('Interne PayPal-Buchungen');
        $response->assertSee('HOLD1');
        $response->assertSee('REL1');
        $response->assertDontSee('WD1');
    }
}
