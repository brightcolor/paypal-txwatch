<?php

namespace App\Console\Commands;

use App\Jobs\SyncPaypalAccountJob;
use App\Models\PaypalAccount;
use App\Models\SyncRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Manual/backfill sync trigger, e.g.:
 *   php artisan paypal:sync --account=1 --from=2026-01-01 --to=2026-12-31
 *   php artisan paypal:sync --account=all --from="2026-07-01 00:00" --to="2026-07-09 12:00"
 */
#[Signature('paypal:sync {--account=all : PaypalAccount ID or "all"} {--from=} {--to=} {--sync : Run inline instead of queueing}')]
#[Description('Trigger a manual PayPal transaction sync/backfill for one or all accounts.')]
class PaypalSyncCommand extends Command
{
    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if (! $from || ! $to) {
            $this->components->error('Bitte --from und --to angeben (z.B. --from=2026-01-01 --to=2026-12-31).');

            return self::FAILURE;
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        if ($start->greaterThanOrEqualTo($end)) {
            $this->components->error('--from muss vor --to liegen.');

            return self::FAILURE;
        }

        $accountOption = $this->option('account');
        $accounts = $accountOption === 'all'
            ? PaypalAccount::query()->where('is_active', true)->get()
            : PaypalAccount::query()->where('id', $accountOption)->get();

        if ($accounts->isEmpty()) {
            $this->components->error('Kein passendes aktives PayPal-Konto gefunden.');

            return self::FAILURE;
        }

        foreach ($accounts as $account) {
            $job = new SyncPaypalAccountJob(
                paypalAccountId: $account->id,
                start: $start->toIso8601String(),
                end: $end->toIso8601String(),
                type: SyncRun::TYPE_BACKFILL,
            );

            if ($this->option('sync')) {
                $this->components->info("Sync für Konto [{$account->name}] läuft synchron...");
                app(\App\Services\Sync\SyncService::class)->run($account, $start, $end, SyncRun::TYPE_BACKFILL);
            } else {
                dispatch($job);
                $this->components->info("Sync für Konto [{$account->name}] eingereiht ({$start} bis {$end}).");
            }
        }

        return self::SUCCESS;
    }
}
