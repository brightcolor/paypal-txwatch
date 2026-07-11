<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\PaypalAccount;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reporting\ReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerScopeTest extends TestCase
{
    use RefreshDatabase;

    private PaypalAccount $account;
    private Customer $customerA;
    private Customer $customerB;
    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);

        $this->customerA = Customer::create(['name' => 'Verein A', 'is_active' => true]);
        $this->customerB = Customer::create(['name' => 'Verein B', 'is_active' => true]);

        $eventA = Event::create(['name' => 'Event A', 'customer_id' => $this->customerA->id, 'is_active' => true]);
        $eventB = Event::create(['name' => 'Event B', 'customer_id' => $this->customerB->id, 'is_active' => true]);

        $this->tx($eventA->id, 100);
        $this->tx($eventB->id, 200);

        $this->userA = User::factory()->create(['is_active' => true, 'customer_id' => $this->customerA->id]);
        $this->userA->assignRole(Role::findByName('customer'));
    }

    private function tx(int $eventId, float $gross): void
    {
        static $n = 0;
        $n++;
        Transaction::create([
            'paypal_account_id' => $this->account->id, 'event_id' => $eventId,
            'transaction_id' => 'T' . $n, 'transaction_event_code' => 'T0006',
            'gross_amount' => $gross, 'fee_amount' => -1, 'net_amount' => $gross - 1, 'currency' => 'EUR',
            'transaction_initiation_date' => now()->subDay(),
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'h' . $n), 'dedupe_key' => hash('sha256', 'd' . $n),
            'imported_at' => now(),
        ]);
    }

    public function test_reports_are_scoped_to_the_customers_own_events(): void
    {
        $this->actingAs($this->userA);

        $byEvent = app(ReportService::class)->feesByEvent();

        $this->assertCount(1, $byEvent);
        $this->assertSame('Event A', $byEvent->first()['label']);
        $this->assertSame(100.0, $byEvent->first()['gross']);
    }

    public function test_admin_sees_all_events(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);

        $this->assertCount(2, app(ReportService::class)->feesByEvent());
    }

    public function test_customer_without_customer_id_sees_nothing(): void
    {
        $orphan = User::factory()->create(['customer_id' => null]);
        $orphan->assignRole(Role::findByName('customer'));
        $this->actingAs($orphan);

        $this->assertCount(0, app(ReportService::class)->feesByEvent());
    }

    public function test_settlements_are_scoped_to_the_customer(): void
    {
        $base = ['status' => Settlement::STATUS_OPEN, 'blocks' => [], 'events' => [], 'gross' => 0, 'fees' => 0, 'payout' => 0, 'vat' => 0, 'net_excl_vat' => 0, 'tx_count' => 0];
        Settlement::create($base + ['customer_id' => $this->customerA->id, 'title' => 'A']);
        Settlement::create($base + ['customer_id' => $this->customerB->id, 'title' => 'B']);

        $this->actingAs($this->userA);

        $scoped = \App\Support\CustomerScope::byCustomerId(Settlement::query())->get();
        $this->assertCount(1, $scoped);
        $this->assertSame('A', $scoped->first()->title);
    }
}
