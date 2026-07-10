<?php

namespace Tests\Feature;

use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionViewPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(\Spatie\Permission\Models\Role::findOrCreate('admin'));

        return $user;
    }

    public function test_view_page_renders_for_a_transaction_with_nested_cart_item_arrays(): void
    {
        $account = PaypalAccount::create([
            'name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y',
        ]);

        // Reproduces a real PayPal payload shape (cart_info.item_details is a
        // list of nested associative arrays) that previously crashed the
        // Raw-JSON infolist entry with "Array to string conversion".
        $transaction = Transaction::create([
            'paypal_account_id' => $account->id,
            'transaction_id' => 'TXN1',
            'gross_amount' => 33.00,
            'fee_amount' => -1.38,
            'net_amount' => 31.62,
            'currency' => 'EUR',
            'raw_payload' => [
                'transaction_info' => ['transaction_id' => 'TXN1'],
                'cart_info' => [
                    'item_details' => [
                        ['item_name' => 'Ticket A', 'item_quantity' => '1', 'item_unit_price' => ['currency_code' => 'EUR', 'value' => '33.00']],
                        ['item_name' => 'Ticket B', 'item_quantity' => '2', 'item_unit_price' => ['currency_code' => 'EUR', 'value' => '10.00']],
                    ],
                ],
            ],
            'raw_hash' => hash('sha256', 'x'),
            'dedupe_key' => hash('sha256', 'y'),
            'imported_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())
            ->get("/admin/transactions/{$transaction->id}");

        $response->assertSuccessful();
    }
}
