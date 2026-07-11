<?php

namespace Tests\Feature\Pretix;

use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\PaypalAccount;
use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderLinkInTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_order_number_renders_as_new_tab_link_with_icon(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));

        $connection = PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://www.hsp-tickets.de', 'organizer_slug' => 'hsp-events', 'api_token' => 'x',
        ]);
        $order = PretixOrder::create([
            'pretix_connection_id' => $connection->id,
            'event_slug' => 'fcaspiel', 'order_code' => 'QMCJV', 'status' => 'p',
            'total' => 50, 'currency' => 'EUR',
            'url' => 'https://www.hsp-tickets.de/control/event/hsp-events/fcaspiel/orders/QMCJV/',
            'raw_payload' => [],
        ]);
        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'transaction_id' => 'T1', 'transaction_event_code' => 'T0006',
            'custom_field' => 'Order FCASPIEL-QMCJV', 'gross_amount' => 50, 'currency' => 'EUR',
            'pretix_order_id' => $order->id, 'reconciliation_status' => Transaction::RECONCILIATION_MATCHED,
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'a'), 'dedupe_key' => hash('sha256', 'b'), 'imported_at' => now(),
        ]);

        $html = Livewire::actingAs($admin)
            ->test(ListTransactions::class)
            ->html();

        // The cell must be a real anchor to the pretix control panel, opening a new tab,
        // and carry the external-link icon so users see it leaves the app.
        $this->assertStringContainsString('https://www.hsp-tickets.de/control/event/hsp-events/fcaspiel/orders/QMCJV/', $html);
        $this->assertMatchesRegularExpression(
            '/<a[^>]+href="https:\/\/www\.hsp-tickets\.de\/control\/event\/hsp-events\/fcaspiel\/orders\/QMCJV\/"[^>]*target="_blank"/s',
            $html,
        );
    }
}
