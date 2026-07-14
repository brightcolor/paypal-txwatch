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
     * @return array{imported: int, matched: int, pretix_proposed: int}
     */
    public function sync(FintsConnection $connection): array
    {
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
