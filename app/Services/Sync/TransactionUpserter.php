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

        // Latest current revision of the same PayPal transaction, if any.
        $prior = filled($normalized['transaction_id'] ?? null)
            ? Transaction::query()
                ->where('paypal_account_id', $account->id)
                ->where('transaction_id', $normalized['transaction_id'])
                ->whereNull('superseded_at')
                ->orderByDesc('transaction_updated_date')
                ->orderByDesc('id')
                ->first()
            : null;

        $assignment = $this->assigner->assign($normalized);

        // A manual event assignment on the previous revision must survive the
        // new revision - otherwise a PayPal status update silently undoes what
        // the operator assigned by hand (audit 2026-07-12).
        if ($prior && $prior->assignment_method === 'manual') {
            $assignment = [
                'event_id' => $prior->event_id,
                'assignment_method' => 'manual',
                'assignment_rule_id' => null,
                'assigned_at' => $prior->assigned_at,
            ];
        }

        // Revision bookkeeping: exactly ONE row per transaction_id is "current"
        // (superseded_at null) and only that one counts in revenue sums. An
        // out-of-order OLDER revision (late re-sync of an old window) is stored
        // as already superseded so it can never displace newer data.
        $incomingIsNewer = ! $prior
            || $prior->transaction_updated_date === null
            || ($normalized['transaction_updated_date'] ?? null) === null
            || $normalized['transaction_updated_date'] >= $prior->transaction_updated_date;

        if ($prior && $incomingIsNewer) {
            Transaction::query()
                ->where('paypal_account_id', $account->id)
                ->where('transaction_id', $normalized['transaction_id'])
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);
        }

        Transaction::create(array_merge($normalized, $assignment, [
            'imported_at' => now(),
            'last_seen_at' => now(),
            'superseded_at' => ($prior && ! $incomingIsNewer) ? now() : null,
        ]));

        return $prior ? 'updated' : 'imported';
    }
}
