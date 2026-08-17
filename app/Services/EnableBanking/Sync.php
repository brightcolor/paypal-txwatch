<?php

namespace App\Services\EnableBanking;

use App\Models\EnableBankingConnection;
use App\Services\Bank\BankStatementImporter;
use Throwable;

/**
 * Pulls transactions for the active Enable Banking connection and feeds them
 * through the shared import + reconcile pipeline - the same one the CAMT/MT940
 * file import and the FinTS path use. Called by the daily scheduler and by the
 * manual "Jetzt abrufen" button.
 */
class Sync
{
    public function __construct(
        private readonly Client $client,
        private readonly TransactionMapper $mapper,
        private readonly BankStatementImporter $importer,
        private readonly JournalWriter $journal,
    ) {
    }

    /**
     * Is an unattended pull allowed right now?
     *
     * PSD2 grants four accesses a day without the account holder present, which
     * is why the scheduler runs every six hours. But a restarted scheduler fires
     * again on the next tick, and once the quota is spent the bank refuses until
     * midnight - the feed would be dead for the rest of the day for no reason.
     *
     * Returns the reason to skip, or null when the way is clear. Only the
     * SCHEDULED path asks; a manual pull counts as attended and is not limited.
     */
    public function tooSoon(EnableBankingConnection $connection): ?string
    {
        $gap = (int) config('bank.enablebanking.min_hours_between_pulls');

        if ($gap <= 0 || $connection->last_synced_at === null) {
            return null;
        }

        $next = $connection->last_synced_at->copy()->addHours($gap);

        if ($next->isFuture()) {
            return sprintf(
                'Letzter Abruf war %s. PSD2 erlaubt vier Abrufe am Tag ohne Anwender; der nächste '
                . 'planmässige ist ab %s möglich.',
                $connection->last_synced_at->format('d.m.Y H:i'),
                $next->format('d.m.Y H:i'),
            );
        }

        return null;
    }

    /**
     * @return array{recorded: int, known: int, with_order: int, imported: int, matched: int, pretix_proposed: int, skipped_pending: int, truncation_notice: ?string, mode: string}
     */
    public function sync(EnableBankingConnection $connection): array
    {
        if (! $connection->isActive()) {
            throw new EnableBankingException(
                $connection->consentExpired()
                    ? 'Die Zustimmung der Bank ist abgelaufen. Bitte die Bank unter „Bank verbinden" erneut freigeben.'
                    : 'Keine aktive Bankverbindung über Enable Banking.'
            );
        }

        $accountUid = $connection->accountUid();

        if (blank($accountUid)) {
            throw new EnableBankingException(
                'Zu dieser Verbindung ist kein Konto hinterlegt. Bitte die Bank erneut verbinden.'
            );
        }

        $to = now();

        /*
         * Re-cover a few days on every run so late bookings are not missed; on
         * the very first run go back further. Same values and same reason as the
         * FinTS sync - a bank can book something with yesterday's value date
         * after today's pull already ran.
         */
        $from = $connection->last_synced_at
            ? $connection->last_synced_at->copy()->subDays((int) config('bank.enablebanking.overlap_days'))
            : now()->subDays((int) config('bank.enablebanking.first_pull_days'));

        $page = $this->client->transactions($accountUid, $from, $to);

        $mapped = $this->mapper->map($page->transactions);

        /*
         * THE JOURNAL IS WRITTEN IN BOTH MODES, and on purpose: it is the record
         * of what the bank actually sent, and that record should not disappear the
         * day the entries start counting. In 'import' mode the same entries
         * additionally go through the shared pipeline.
         */
        $journal = $this->journal->record($mapped['entries']);

        $mode = (string) config('bank.enablebanking.mode');

        $import = $mode === 'import'
            ? $this->importer->importEntries($mapped['entries'])
            : ['imported' => 0, 'matched' => 0, 'pretix_proposed' => 0];

        $connection->forceFill([
            'status' => EnableBankingConnection::STATUS_ACTIVE,
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();

        return [
            'recorded' => $journal['recorded'],
            'known' => $journal['known'],
            'with_order' => $journal['with_order'],
            'imported' => $import['imported'],
            'matched' => $import['matched'],
            'pretix_proposed' => $import['pretix_proposed'] ?? 0,
            'skipped_pending' => $mapped['skipped_pending'],
            'mode' => $mode,
            /*
             * PASSED ALL THE WAY UP, not swallowed here. If a cap bit, the
             * caller has to be able to say so - a pull that reports "40 new"
             * while the bank held 300 more looks like success and is a gap in
             * the books.
             */
            'truncation_notice' => $page->truncationNotice(),
        ];
    }

    /**
     * Safe wrapper for the scheduler: records errors instead of throwing.
     *
     * @return array<string, mixed>
     */
    public function syncSafely(EnableBankingConnection $connection): array
    {
        try {
            return $this->sync($connection);
        } catch (Throwable $e) {
            /*
             * An expired consent is flagged as such rather than as a generic
             * error: it needs the account holder at their bank's login, which is
             * a different action from "look at the log".
             */
            $expired = $connection->consentExpired()
                || ($e instanceof EnableBankingException
                    && in_array($e->errorCode, ['SESSION_EXPIRED', 'SESSION_INVALID'], true));

            $connection->forceFill([
                'status' => $expired
                    ? EnableBankingConnection::STATUS_EXPIRED
                    : EnableBankingConnection::STATUS_ERROR,
                'last_error' => $e->getMessage(),
            ])->save();

            return [
                'imported' => 0,
                'matched' => 0,
                'needs_reauth' => $expired,
                'error' => $e->getMessage(),
            ];
        }
    }
}
