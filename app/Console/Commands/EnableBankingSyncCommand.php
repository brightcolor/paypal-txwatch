<?php

namespace App\Console\Commands;

use App\Models\EnableBankingConnection;
use App\Services\EnableBanking\Sync;
use App\Support\AdminNotifier;
use Illuminate\Console\Command;

/**
 * Daily Enable Banking pull: fetches new transactions through the PSD2 aggregator
 * and reconciles them.
 *
 * TWO WARNINGS, NOT ONE, and that is the whole reason this command is more than
 * three lines:
 *
 *  - the consent HAS lapsed - the pull is dead until someone re-authorises;
 *  - the consent is ABOUT TO lapse - still working, but not for long.
 *
 * The second is the one that matters. PSD2 caps consent at 90 days, and
 * re-authorising needs the account holder in front of their bank's login. Finding
 * out on the morning it already stopped means the books have a gap by the time
 * anyone looks.
 */
class EnableBankingSyncCommand extends Command
{
    protected $signature = 'enablebanking:sync';

    protected $description = 'Fetch bank transactions via Enable Banking (PSD2) and reconcile them.';

    private const SETTINGS_URL = '/admin/bank-verbinden';

    public function handle(Sync $sync): int
    {
        $connection = EnableBankingConnection::current();

        if ($connection->status === EnableBankingConnection::STATUS_NEW) {
            $this->info('Keine Bankverbindung über Enable Banking – übersprungen.');

            return self::SUCCESS;
        }

        if ($connection->consentExpired()) {
            AdminNotifier::warn(
                'Bank-Freigabe abgelaufen',
                'Die Zustimmung der Bank ist abgelaufen – es kommen keine Umsätze mehr herein. '
                . 'Bitte unter „Bank → Bank verbinden" erneut freigeben.',
                url(self::SETTINGS_URL),
            );
            $this->warn('Zustimmung abgelaufen – erneute Freigabe nötig.');

            return self::SUCCESS;
        }

        /*
         * THE QUOTA GUARD, and it belongs here rather than in the scheduler
         * expression: `everySixHours()` is exactly the four accesses PSD2 grants
         * without the account holder present, but a scheduler that gets restarted
         * fires again on the next tick. Two extra runs and the bank refuses until
         * midnight - the feed would be dead for the rest of the day, and nobody
         * would connect that to a container recreation hours earlier.
         */
        if ($reason = $sync->tooSoon($connection)) {
            $this->info($reason);

            return self::SUCCESS;
        }

        $result = $sync->syncSafely($connection);

        if (! empty($result['needs_reauth'])) {
            AdminNotifier::warn(
                'Bank-Freigabe abgelaufen',
                'Die Bank hat den Zugriff beendet. Bitte unter „Bank → Bank verbinden" erneut freigeben.',
                url(self::SETTINGS_URL),
            );
            $this->warn('Erneute Freigabe nötig.');

            return self::SUCCESS;
        }

        if (isset($result['error'])) {
            $this->error('Abruf fehlgeschlagen: ' . $result['error']);

            return self::SUCCESS;
        }

        /*
         * Reports what actually happened, and the journal mode is named as such.
         * "0 neu importiert" on a pull that recorded forty transactions would look
         * like a failure, when it is exactly the configured behaviour.
         */
        if (($result['mode'] ?? 'journal') === 'journal') {
            $this->info(sprintf(
                'Abruf fertig (Journal, es wird nichts gebucht): %d neu aufgezeichnet, %d schon bekannt, '
                . '%d mit erkannter pretix-Bestellnummer.',
                $result['recorded'] ?? 0,
                $result['known'] ?? 0,
                $result['with_order'] ?? 0,
            ));
        } else {
            $this->info("Abruf fertig: {$result['imported']} neu, {$result['matched']} zugeordnet.");
        }

        /*
         * A cap that bit is escalated to the admins, not just written to a log
         * line nobody reads. An incomplete pull that reports success is the one
         * failure mode that quietly corrupts the books.
         */
        if (filled($result['truncation_notice'] ?? null)) {
            AdminNotifier::warn('Bankabruf unvollständig', $result['truncation_notice'], url('/admin/bank-transactions'));
            $this->warn($result['truncation_notice']);
        }

        /*
         * Warned about ahead of time, once it is inside the window. Deliberately
         * on every run in those last days rather than exactly once: the notice
         * has to survive being clicked away.
         */
        if ($connection->fresh()?->consentEndsSoon()) {
            $left = $connection->fresh()?->consentDaysLeft();

            AdminNotifier::warn(
                'Bank-Freigabe läuft aus',
                sprintf(
                    'Die Zustimmung der Bank gilt nur noch %d Tage. Danach kommen keine Umsätze mehr '
                    . 'herein, bis sie erneuert wird – das geht nur mit Anmeldung bei der Bank.',
                    (int) $left,
                ),
                url(self::SETTINGS_URL),
            );
            $this->warn(sprintf('Zustimmung läuft in %d Tagen ab.', (int) $left));
        }

        return self::SUCCESS;
    }
}
