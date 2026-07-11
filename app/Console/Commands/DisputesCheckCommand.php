<?php

namespace App\Console\Commands;

use App\Services\PayPal\DisputesOverview;
use App\Support\AdminNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Alerts admins about *newly seen* open PayPal disputes so they can respond
 * before the buyer's window closes and it becomes a chargeback. Only fires on
 * dispute IDs not seen before (tracked in cache), so it won't nag every run.
 */
class DisputesCheckCommand extends Command
{
    protected $signature = 'disputes:check';

    protected $description = 'Notify admins about newly seen open PayPal disputes.';

    private const SEEN_KEY = 'paypal_seen_dispute_ids';

    public function handle(DisputesOverview $overview): int
    {
        $open = $overview->all(fresh: true);

        if ($open->isEmpty()) {
            $this->info('Keine offenen Disputes.');

            return self::SUCCESS;
        }

        $seen = (array) Cache::get(self::SEEN_KEY, []);
        $currentIds = $open->pluck('id')->filter()->all();
        $new = array_diff($currentIds, $seen);

        if (! empty($new)) {
            $count = count($new);
            AdminNotifier::warn(
                'Neue PayPal-Käuferkonflikte',
                "{$count} neue(r) offene(r) Dispute(s). Bitte zeitnah bearbeiten, bevor daraus Rückbuchungen werden.",
                url('/admin/disputes'),
            );
            $this->warn("{$count} neue Disputes gemeldet.");
        } else {
            $this->info('Keine neuen Disputes.');
        }

        // Remember all currently-open IDs for 30 days so resolved ones can
        // eventually re-alert if they reopen.
        Cache::put(self::SEEN_KEY, $currentIds, now()->addDays(30));

        return self::SUCCESS;
    }
}
