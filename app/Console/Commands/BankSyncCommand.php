<?php

namespace App\Console\Commands;

use App\Models\BankConnection;
use App\Services\Bank\GoCardlessSync;
use App\Support\AdminNotifier;
use Illuminate\Console\Command;

/**
 * Daily GoCardless pull: fetches new bank transactions and reconciles them.
 * Also flips the connection to "expired" and warns admins when the 90-day PSD2
 * consent is about to run out (or has), since re-authorising needs a manual TAN.
 */
class BankSyncCommand extends Command
{
    protected $signature = 'bank:sync';

    protected $description = 'Fetch bank transactions via GoCardless and reconcile them.';

    public function handle(GoCardlessSync $sync): int
    {
        $connection = BankConnection::current();

        if (! $connection->isConnected()) {
            $this->info('Keine verbundene Bankverbindung – übersprungen.');

            return self::SUCCESS;
        }

        $daysLeft = $connection->consentDaysLeft();

        if ($daysLeft !== null && $daysLeft <= 0) {
            $connection->update(['status' => BankConnection::STATUS_EXPIRED]);
            AdminNotifier::warn('Bank-Freigabe abgelaufen',
                'Die PSD2-Freigabe für den Bankabruf ist abgelaufen. Bitte unter „Bank → Auto-Abruf" neu freigeben.',
                url('/admin/bank-connection'));
            $this->warn('Consent abgelaufen.');

            return self::SUCCESS;
        }

        if ($daysLeft !== null && $daysLeft <= 7) {
            AdminNotifier::warn('Bank-Freigabe läuft bald ab',
                "Die PSD2-Freigabe für den Bankabruf läuft in {$daysLeft} Tag(en) ab. Bitte rechtzeitig neu freigeben.",
                url('/admin/bank-connection'));
        }

        $result = $sync->syncSafely($connection);

        if (isset($result['error'])) {
            $this->error('Abruf fehlgeschlagen: ' . $result['error']);

            return self::SUCCESS;
        }

        $this->info("Abruf fertig: {$result['imported']} neu, {$result['matched']} zugeordnet.");

        return self::SUCCESS;
    }
}
