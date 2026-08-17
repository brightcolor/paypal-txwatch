<?php

namespace App\Services\Bank;

use App\Models\FintsConnection;
use Throwable;

/**
 * Pulls statements for the active FinTS connection and feeds them through the
 * shared import + reconcile pipeline. Called by the daily scheduler and the
 * manual "Jetzt abrufen" button. Refreshes and re-persists the FinTS session on
 * every run so the (up to 90-day) login stays warm.
 */
class FintsSync
{
    public function __construct(
        private readonly FintsMapper $mapper,
        private readonly BankStatementImporter $importer,
    ) {
    }

    /**
     * Reason the FinTS path is closed, or null while it is open.
     *
     * Public and static because three very different callers need the exact same
     * sentence: the settings page (as a notice), the console command (as a log
     * line) and this service (as an exception). Three hand-written variants of
     * one explanation drift apart, and the one the operator happens to read is
     * then the outdated one.
     */
    public static function disabledReason(): ?string
    {
        if (config('bank.fints.enabled')) {
            return null;
        }

        return 'Der FinTS-Abruf ist stillgelegt: Ohne bei der Deutschen Kreditwirtschaft '
            . 'freigeschaltete Registrierungsnummer weist der Bankrechner jeden Abruf ab – auch bei '
            . 'richtigen Zugangsdaten, und erst nach erfolgreicher Anmeldung. Kontoumsätze kommen '
            . 'über „Bank verbinden" (Enable Banking) oder den Kontoauszug-Import herein.';
    }

    /**
     * @return array{imported: int, matched: int, pretix_proposed: int}
     */
    public function sync(FintsConnection $connection): array
    {
        /*
         * THE CHOKE POINT, and that is why the guard sits here rather than on
         * the buttons.
         *
         * Every route to the bank runs through this method: the "Jetzt abrufen"
         * action, the daily `bank:sync` command and the scheduler behind it. A
         * check placed only on the settings page would leave the scheduler
         * happily dialling the bank every morning at 06:30 - the kind of second
         * door that makes a switch look like it works while it doesn't.
         */
        if ($reason = self::disabledReason()) {
            throw new \RuntimeException($reason);
        }

        if (! $connection->isActive()) {
            throw new \RuntimeException('Keine aktive FinTS-Bankverbindung.');
        }

        $client = new FintsClient($connection);

        $to = new \DateTime();
        // Re-cover a few days on every run so late bookings are not missed; on
        // the very first run go back 90 days. Carbon extends \DateTime, so these
        // instances satisfy the phpFinTS ?\DateTime parameters directly.
        $from = $connection->last_synced_at
            ? $connection->last_synced_at->copy()->subDays(3)
            : now()->subDays(90);

        $result = $client->sync($connection->persisted_state, $from, $to, $connection->iban);

        $entries = $this->mapper->map($result['transactions']);
        $import = $this->importer->importEntries($entries);

        $connection->forceFill([
            'persisted_state' => $result['state'],
            'iban' => $connection->iban ?: $result['iban'],
            'status' => FintsConnection::STATUS_ACTIVE,
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();

        return [
            'imported' => $import['imported'],
            'matched' => $import['matched'],
            'pretix_proposed' => $import['pretix_proposed'] ?? 0,
        ];
    }

    /** Safe wrapper for the scheduler: records errors instead of throwing. */
    public function syncSafely(FintsConnection $connection): array
    {
        try {
            return $this->sync($connection);
        } catch (FintsNeedsTanException $e) {
            $connection->forceFill([
                'status' => FintsConnection::STATUS_NEEDS_REAUTH,
                'last_error' => $e->getMessage(),
            ])->save();

            return ['imported' => 0, 'matched' => 0, 'needs_reauth' => true, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            $connection->forceFill(['last_error' => $e->getMessage()])->save();

            return ['imported' => 0, 'matched' => 0, 'error' => $e->getMessage()];
        }
    }
}
