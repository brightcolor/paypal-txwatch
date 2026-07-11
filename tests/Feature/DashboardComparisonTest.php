<?php

namespace Tests\Feature;

use App\Filament\Widgets\ComparisonStatsWidget;
use App\Filament\Widgets\TopEventsWidget;
use App\Models\Event;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_comparison_and_top_events_widgets_render_with_data(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));

        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        $event = Event::create(['name' => 'Sommerfest', 'is_active' => true]);

        $n = 0;
        $make = function (float $gross, string $date) use ($account, $event, &$n) {
            $n++;
            Transaction::create([
                'paypal_account_id' => $account->id, 'event_id' => $event->id,
                'transaction_id' => 'T' . $n, 'transaction_event_code' => 'T0006',
                'gross_amount' => $gross, 'fee_amount' => -1, 'net_amount' => $gross - 1, 'currency' => 'EUR',
                'transaction_initiation_date' => $date,
                'raw_payload' => [], 'raw_hash' => hash('sha256', 'h' . $n), 'dedupe_key' => hash('sha256', 'd' . $n),
                'imported_at' => now(),
            ]);
        };

        $make(100, now()->startOfMonth()->addDay()->toDateTimeString());
        $make(50, now()->subMonthNoOverflow()->startOfMonth()->addDay()->toDateTimeString());

        Livewire::actingAs($admin)->test(ComparisonStatsWidget::class)->assertOk()->assertSee('Umsatz diesen Monat');

        $this->assertTrue(TopEventsWidget::canView());
        Livewire::actingAs($admin)->test(TopEventsWidget::class)->assertOk()->assertSee('Sommerfest');
    }
}
