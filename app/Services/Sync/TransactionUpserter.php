<?php

namespace App\Services\Sync;

use App\Models\PaypalAccount;
use App\Models\Transaction;

/**
 * The single idempotent-upsert implementation shared by every ingestion
 * path (API sync, CSV import, ...), so "don't blindly dedupe on
 * transaction_id" stays true no matter where a transaction came from.
 *
 * Keyed on dedupe_key. Because the dedupe key embeds transaction_updated_date
 * + a content hash, a genuinely changed transaction (status transition, fee
 * correction, ...) creates a new revision row rather than overwriting
 * history, while an exact repeat (e.g. re-fetched via the lookback window,
 * or the same row re-imported from a CSV) is a no-op.
 */
class TransactionUpserter
{
    public function __construct(private readonly EventAssigner $assigner)
    {
    }

    /**
     * @param  array<string, mixed>  $normalized  see TransactionNormalizer::normalize() / CsvTransactionNormalizer::normalize()
     * @return string 'imported'|'updated'|'skipped'
     */
    public function upsert(PaypalAccount $account, array $normalized): string
    {
        $existing = Transaction::query()->where('dedupe_key', $normalized['dedupe_key'])->first();

        if ($existing) {
            $existing->forceFill(['last_seen_at' => now()])->save();

            return 'skipped';
        }

        $hasPriorRevision = Transaction::query()
            ->where('paypal_account_id', $account->id)
            ->where('transaction_id', $normalized['transaction_id'])
            ->exists();

        $assignment = $this->assigner->assign($normalized);

        Transaction::create(array_merge($normalized, $assignment, [
            'imported_at' => now(),
            'last_seen_at' => now(),
        ]));

        return $hasPriorRevision ? 'updated' : 'imported';
    }
}
