<?php

namespace App\Console\Commands;

use App\Models\FintsConnection;
use App\Services\Bank\FintsSync;
use App\Support\AdminNotifier;
use Illuminate\Console\Command;

/**
 * Daily FinTS pull: fetches new bank statements directly from the bank and
 * reconciles them. If the bank demands a fresh TAN (strong auth expired), the
 * connection is flipped to "needs_reauth" and admins are warned, since
 * re-authorising needs a manual TAN on the FinTS settings page.
 */
class BankSyncCommand extends Command
{
    protected $signature = 'bank:sync';

    protected $description = 'Fetch bank statements via FinTS/HBCI and reconcile them.';

    public function handle(FintsSync $sync): int
    {
        /*
         * Checked here as well as in FintsSync, and not because one of them is
         * redundant: this one keeps the daily 06:30 run from touching the
         * database or writing a "last_error" for something that is a
         * configuration decision, not a failure. It also reads as a plain line
         * in the scheduler log instead of a stack trace.
         */
        if ($reason = FintsSync::disabledReason()) {
            $this->info($reason);

            return self::SUCCESS;
        }

        $connection = FintsConnection::current();

        if (! $connection->isActive()) {
            $this->info('Keine aktive FinTS-Bankverbindung – übersprungen.');

            return self::SUCCESS;
        }

        $result = $sync->syncSafely($connection);

        if (! empty($result['needs_reauth'])) {
            AdminNotifier::warn('Bank-Anmeldung abgelaufen',
                'Die Bank verlangt eine erneute TAN-Freigabe. Bitte unter „Bank → Auto-Abruf (FinTS)" neu anmelden.',
                url('/admin/bank-connection'));
            $this->warn('TAN-Freigabe erforderlich.');

            return self::SUCCESS;
        }

        if (isset($result['error'])) {
            $this->error('Abruf fehlgeschlagen: ' . $result['error']);

            return self::SUCCESS;
        }

        $this->info("Abruf fertig: {$result['imported']} neu, {$result['matched']} zugeordnet.");

        return self::SUCCESS;
    }
}
