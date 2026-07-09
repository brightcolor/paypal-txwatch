<?php

namespace App\Services\Sync;

use App\Models\ImportError;
use App\Models\PaypalAccount;
use App\Models\SyncRun;
use App\Models\User;

/**
 * Imports PayPal "Activity Download" CSV rows using the same normalized
 * shape and idempotent upsert as the API sync path - useful as a fallback
 * when API/Transaction-Search permissions are unavailable.
 */
class CsvImportService
{
    public function __construct(
        private readonly CsvTransactionNormalizer $normalizer,
        private readonly TransactionUpserter $upserter,
    ) {
    }

    /**
     * @param  array<int, array<string, string>>  $rows  raw CSV rows, keyed by original column header
     * @param  array<string, ?string>  $mapping  target field => source column header (see CsvColumnGuesser)
     */
    public function import(PaypalAccount $account, array $rows, array $mapping, ?User $triggeredBy = null): SyncRun
    {
        $run = SyncRun::create([
            'paypal_account_id' => $account->id,
            'type' => SyncRun::TYPE_CSV_IMPORT,
            'status' => SyncRun::STATUS_RUNNING,
            'window_start' => now(),
            'window_end' => now(),
            'started_at' => now(),
            'triggered_by_user_id' => $triggeredBy?->id,
        ]);

        foreach ($rows as $rawRow) {
            $this->ingestRow($account, $run, $rawRow, $mapping);
        }

        $run->markFinished($run->error_count > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS);

        return $run->fresh();
    }

    /**
     * @param  array<string, ?string>  $mapping
     */
    private function ingestRow(PaypalAccount $account, SyncRun $run, array $rawRow, array $mapping): void
    {
        try {
            $mappedRow = [];

            foreach ($mapping as $field => $header) {
                $mappedRow[$field] = $header !== null ? ($rawRow[$header] ?? null) : null;
            }

            $normalized = $this->normalizer->normalize($account, $rawRow, $mappedRow);

            if (empty($normalized['transaction_id'])) {
                throw new \RuntimeException('Zeile ohne Transaktions-ID übersprungen (Spaltenzuordnung prüfen).');
            }

            if ($normalized['gross_amount'] === null) {
                throw new \RuntimeException('Zeile ohne lesbaren Brutto-Betrag übersprungen (Spaltenzuordnung/Zahlenformat prüfen).');
            }

            $status = $this->upserter->upsert($account, $normalized);
            $run->increment("{$status}_count");
        } catch (\Throwable $e) {
            $run->increment('error_count');

            ImportError::create([
                'sync_run_id' => $run->id,
                'paypal_account_id' => $account->id,
                'transaction_id' => $rawRow[$mapping['transaction_id'] ?? ''] ?? null,
                'error_type' => ImportError::TYPE_VALIDATION,
                'message' => $e->getMessage(),
                'context' => ['raw_row' => $rawRow],
            ]);
        }
    }
}
