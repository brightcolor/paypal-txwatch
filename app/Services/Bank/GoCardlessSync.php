<?php

namespace App\Services\Bank;

use App\Models\BankConnection;
use Throwable;

/**
 * Pulls transactions for all linked accounts of the GoCardless connection and
 * feeds them through the shared import + reconcile pipeline. Called by the
 * daily scheduler and the manual "Jetzt abrufen" button.
 */
class GoCardlessSync
{
    public function __construct(
        private readonly GoCardlessMapper $mapper,
        private readonly BankStatementImporter $importer,
    ) {
    }

    /**
     * @return array{imported: int, matched: int, accounts: int}
     */
    public function sync(BankConnection $connection): array
    {
        if (! $connection->isConnected()) {
            throw new \RuntimeException('Keine verbundene Bankverbindung.');
        }

        $client = new GoCardlessClient($connection);

        $entries = [];
        foreach ((array) $connection->account_ids as $accountId) {
            $entries = array_merge($entries, $this->mapper->map($client->accountTransactions($accountId)));
        }

        $result = $this->importer->importEntries($entries);

        $connection->update(['last_synced_at' => now(), 'last_error' => null]);

        return [
            'imported' => $result['imported'],
            'matched' => $result['matched'],
            'accounts' => count((array) $connection->account_ids),
        ];
    }

    /** Safe wrapper for the scheduler: records errors instead of throwing. */
    public function syncSafely(BankConnection $connection): array
    {
        try {
            return $this->sync($connection);
        } catch (Throwable $e) {
            $connection->update(['last_error' => $e->getMessage()]);

            return ['imported' => 0, 'matched' => 0, 'accounts' => 0, 'error' => $e->getMessage()];
        }
    }
}
