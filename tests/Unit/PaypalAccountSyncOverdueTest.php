<?php

namespace Tests\Unit;

use App\Models\PaypalAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaypalAccountSyncOverdueTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $overrides = []): PaypalAccount
    {
        $lastSuccess = $overrides['last_successful_sync_at'] ?? null;
        unset($overrides['last_successful_sync_at']);

        $account = PaypalAccount::create(array_merge([
            'name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y',
            'sync_enabled' => true, 'sync_interval_minutes' => 15,
        ], $overrides));

        // last_successful_sync_at is intentionally not mass-assignable (only
        // ever set by SyncService), so tests need forceFill to set it directly.
        $account->forceFill(['last_successful_sync_at' => $lastSuccess])->save();

        return $account->fresh();
    }

    public function test_recently_synced_account_is_not_overdue(): void
    {
        $account = $this->account(['last_successful_sync_at' => now()->subMinutes(10)]);

        $this->assertFalse($account->isSyncOverdue());
    }

    public function test_account_with_stale_last_success_is_overdue(): void
    {
        $account = $this->account(['last_successful_sync_at' => now()->subHours(3)]);

        $this->assertTrue($account->isSyncOverdue());
    }

    public function test_disabled_sync_is_never_flagged_overdue(): void
    {
        $account = $this->account(['sync_enabled' => false, 'last_successful_sync_at' => now()->subDays(30)]);

        $this->assertFalse($account->isSyncOverdue());
    }

    public function test_never_synced_new_account_is_not_immediately_flagged(): void
    {
        $account = $this->account(['last_successful_sync_at' => null]);

        $this->assertFalse($account->isSyncOverdue());
    }

    public function test_never_synced_old_account_is_flagged(): void
    {
        $account = $this->account(['last_successful_sync_at' => null]);
        $account->forceFill(['created_at' => now()->subHours(5)])->save();

        $this->assertTrue($account->fresh()->isSyncOverdue());
    }

    public function test_threshold_scales_with_long_sync_intervals(): void
    {
        // 60-minute interval -> threshold floor becomes 4h instead of the 2h default.
        $account = $this->account(['sync_interval_minutes' => 60, 'last_successful_sync_at' => now()->subHours(3)]);

        $this->assertFalse($account->isSyncOverdue());
    }
}
