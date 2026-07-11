<?php

namespace Tests\Feature\PayPal;

use App\Models\PaypalAccount;
use App\Models\User;
use App\Services\PayPal\DisputesOverview;
use App\Support\AdminNotifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DisputesClientTest extends TestCase
{
    use RefreshDatabase;

    private function fakePayPal(array $items): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400], 200),
            '*/v1/customer/disputes*' => Http::response(['items' => $items, 'links' => []], 200),
        ]);
    }

    private function account(): PaypalAccount
    {
        return PaypalAccount::create(['name' => 'Haupt', 'mode' => 'live', 'client_id' => 'x', 'client_secret' => 'y', 'is_active' => true]);
    }

    public function test_open_disputes_are_normalized(): void
    {
        $this->account();
        $this->fakePayPal([[
            'dispute_id' => 'PP-D-1', 'status' => 'WAITING_FOR_SELLER_RESPONSE', 'reason' => 'MERCHANDISE_OR_SERVICE_NOT_RECEIVED',
            'dispute_amount' => ['currency_code' => 'EUR', 'value' => '25.00'],
            'create_time' => '2026-07-01T10:00:00Z', 'seller_response_due_date' => '2026-07-10T10:00:00Z',
            'disputed_transactions' => [['seller_transaction_id' => 'TX123']],
        ]]);

        $rows = app(DisputesOverview::class)->all(fresh: true);

        $this->assertCount(1, $rows);
        $this->assertSame('PP-D-1', $rows[0]['id']);
        $this->assertSame(25.0, $rows[0]['amount']);
        $this->assertSame('EUR', $rows[0]['currency']);
        $this->assertSame('TX123', $rows[0]['transaction_id']);
        $this->assertSame('Haupt', $rows[0]['account']);
    }

    public function test_check_command_notifies_only_on_new_disputes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findByName('admin'));
        $this->account();

        $this->fakePayPal([[
            'dispute_id' => 'PP-D-9', 'status' => 'REQUIRED_ACTION',
            'dispute_amount' => ['currency_code' => 'EUR', 'value' => '5.00'], 'create_time' => '2026-07-01T10:00:00Z',
        ]]);

        // First run: new dispute -> one admin notification.
        $this->artisan('disputes:check')->assertSuccessful();
        $this->assertSame(1, \DB::table('notifications')->count());

        // Second run: same dispute, already seen -> no new notification.
        $this->artisan('disputes:check')->assertSuccessful();
        $this->assertSame(1, \DB::table('notifications')->count());
    }
}
