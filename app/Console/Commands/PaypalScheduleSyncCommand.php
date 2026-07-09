<?php

namespace App\Console\Commands;

use App\Jobs\SyncPaypalAccountJob;
use App\Models\PaypalAccount;
use App\Models\SyncRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every minute via the scheduler (routes/console.php). Dispatches a
 * sync job for every account whose configured sync_interval_minutes has
 * elapsed since its last sync attempt. The actual fetch window always
 * re-covers effectiveLookbackHours() so late-arriving PayPal records
 * (up to ~3h delay) get picked up on the next tick.
 */
#[Signature('paypal:schedule-sync')]
#[Description('Dispatch sync jobs for every PayPal account that is due for a sync.')]
class PaypalScheduleSyncCommand extends Command
{
    public function handle(): int
    {
        $due = PaypalAccount::query()
            ->where('is_active', true)
            ->where('sync_enabled', true)
            ->get()
            ->filter(fn (PaypalAccount $account) => $this->isDue($account));

        foreach ($due as $account) {
            $end = now();
            $start = $end->clone()->subHours($account->effectiveLookbackHours());

            dispatch(new SyncPaypalAccountJob(
                paypalAccountId: $account->id,
                start: $start->toIso8601String(),
                end: $end->toIso8601String(),
                type: SyncRun::TYPE_SCHEDULED,
            ));

            $this->components->info("Sync eingereiht für [{$account->name}].");
        }

        if ($due->isEmpty()) {
            $this->components->info('Kein Konto fällig.');
        }

        return self::SUCCESS;
    }

    private function isDue(PaypalAccount $account): bool
    {
        if (! $account->last_synced_at) {
            return true;
        }

        return $account->last_synced_at->addMinutes($account->sync_interval_minutes)->isPast();
    }
}
