<?php

namespace Tests\Feature;

use App\Filament\Widgets\DashboardStatsOverview;
use App\Filament\Widgets\RevenueByDayChart;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DashboardRange;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_presets_resolve_to_expected_bounds(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00');

        [$from, $until] = DashboardRange::resolve(['range' => 'month']);
        $this->assertSame('2026-07-01', $from->toDateString());
        $this->assertSame('2026-07-11', $until->toDateString());

        [$from, $until] = DashboardRange::resolve(['range' => 'last_month']);
        $this->assertSame('2026-06-01', $from->toDateString());
        $this->assertSame('2026-06-30', $until->toDateString());

        [$from, $until] = DashboardRange::resolve(['range' => 'last_year']);
        $this->assertSame('2025-01-01', $from->toDateString());
        $this->assertSame('2025-12-31', $until->toDateString());

        [$from, $until] = DashboardRange::resolve(['range' => 'all']);
        $this->assertNull($from);
        $this->assertNull($until);

        [$from, $until, $label] = DashboardRange::resolve(['range' => 'custom', 'from' => '2026-05-01', 'until' => '2026-05-15']);
        $this->assertSame('2026-05-01', $from->toDateString());
        $this->assertSame('2026-05-15', $until->toDateString());
        $this->assertSame('01.05.2026 – 15.05.2026', $label);

        // Default / unknown -> 30 days INCLUDING today (30 buckets, not 31).
        [$from, $until] = DashboardRange::resolve(null);
        $this->assertSame('2026-06-12', $from->toDateString());

        // "Letzte 7 Tage" = exactly 7 calendar days including today.
        [$from, $until] = DashboardRange::resolve(['range' => '7d']);
        $this->assertSame('2026-07-05', $from->toDateString());
        $this->assertSame(7.0, (float) $from->diffInDays($until->copy()->addSecond()->startOfDay()));

        Carbon::setTestNow();
    }

    public function test_widgets_respect_the_selected_range(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));

        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        $n = 0;
        $make = function (float $gross, string $date) use ($account, &$n) {
            $n++;
            Transaction::create([
                'paypal_account_id' => $account->id, 'transaction_id' => 'T' . $n, 'transaction_event_code' => 'T0006',
                'gross_amount' => $gross, 'fee_amount' => 0, 'net_amount' => $gross, 'currency' => 'EUR',
                'transaction_initiation_date' => $date,
                'raw_payload' => [], 'raw_hash' => hash('sha256', 'h' . $n), 'dedupe_key' => hash('sha256', 'd' . $n),
                'imported_at' => now(),
            ]);
        };

        $make(111.11, now()->subDays(2)->toDateTimeString());     // inside 7d
        $make(999.99, now()->subDays(60)->toDateTimeString());    // outside 7d

        // 7-day range: only the first transaction counts.
        Livewire::actingAs($admin)
            ->test(DashboardStatsOverview::class, ['filters' => ['range' => '7d']])
            ->assertOk()
            ->assertSee('111,11')
            ->assertDontSee('1.111,10'); // sum of both would be 1111.10

        // 90-day range: both count.
        \Illuminate\Support\Facades\Cache::flush();
        Livewire::actingAs($admin)
            ->test(DashboardStatsOverview::class, ['filters' => ['range' => '90d']])
            ->assertOk()
            ->assertSee('1.111,10');

        // Chart renders in both day and month resolution.
        Livewire::actingAs($admin)
            ->test(RevenueByDayChart::class, ['filters' => ['range' => '7d']])
            ->assertOk();
        Livewire::actingAs($admin)
            ->test(RevenueByDayChart::class, ['filters' => ['range' => 'year']])
            ->assertOk();
    }
}
