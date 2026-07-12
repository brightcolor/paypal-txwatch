<?php

namespace App\Services\Pretix;

use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Links PayPal transactions to pretix orders and cross-checks them. PayPal
 * stays authoritative; pretix is the reference we reconcile against:
 *   - matched         : a pretix order exists and its total plausibly equals
 *                       the PayPal amount(s) collected for that order code
 *   - amount_mismatch : a pretix order exists but the totals differ
 *   - unmatched       : the transaction has an order number but no pretix
 *                       order was found for it
 * Transactions without a parseable order number keep a null status.
 */
class PretixReconciler
{
    private const TOLERANCE = 0.01;

    /**
     * @return array{matched: int, mismatch: int, unmatched: int}
     */
    public function reconcile(PretixConnection $connection): array
    {
        $ordersByKey = PretixOrder::query()
            ->where('pretix_connection_id', $connection->id)
            // Only the matching-relevant columns - never the multi-KB raw_payload.
            ->get(['id', 'event_slug', 'order_code', 'total'])
            ->filter(fn (PretixOrder $o) => $o->ownMatchKey() !== null)
            ->keyBy(fn (PretixOrder $o) => $o->ownMatchKey());

        // Same here: loading every transaction's raw PayPal payload into memory
        // scales at ~10KB/row; the reconciliation only needs these columns.
        // save() below only issues an UPDATE for rows whose link/status actually
        // changed (Eloquent dirty check), so re-runs are read-mostly.
        $groups = Transaction::query()
            ->whereNotNull('custom_field')
            ->where('custom_field', '<>', '')
            ->get([
                'id', 'custom_field', 'transaction_id', 'transaction_event_code',
                'is_ledger', 'instrument_type', 'gross_amount',
                'pretix_order_id', 'reconciliation_status',
            ])
            ->groupBy(fn (Transaction $t) => $t->pretixMatchKey());

        // Slugs this connection actually owns. Only keys within that slug
        // space may be touched: reconciling connection A must neither wipe
        // connection B's matches nor mark free-text custom fields (whose
        // parsed "slug" belongs to no connection) as unmatched (audit 2026-07-12).
        $ownSlugs = $ordersByKey->keys()
            ->map(fn (string $key) => explode('/', $key, 2)[0])
            ->unique()
            ->flip();

        $matched = 0;
        $mismatch = 0;
        $unmatched = 0;

        foreach ($groups as $key => $group) {
            if (blank($key)) {
                continue;
            }

            $slugPart = explode('/', (string) $key, 2)[0];

            if (! $ownSlugs->has($slugPart)) {
                continue; // not this connection's event - leave untouched
            }

            // Only real payments count towards the amount paid. Ledger events
            // (holds/reserves T21xx, withdrawals T04xx/T20xx) share the same order
            // code but are internal money movements - a hold RELEASE even has a
            // positive gross that would otherwise inflate the sum and cause a
            // false "amount_mismatch". PayPal can also list the same payment as
            // several revision rows sharing a transaction_id, so dedupe by that.
            $payments = $group->reject(fn (Transaction $t) => $t->isLedgerEvent());
            $paymentCount = $payments->count();

            /** @var PretixOrder|null $order */
            $order = $ordersByKey->get($key);

            if (! $order) {
                $this->link($group, null, Transaction::RECONCILIATION_UNMATCHED);
                $unmatched += $paymentCount;

                continue;
            }

            $paid = (float) $payments
                ->where('gross_amount', '>', 0)
                ->unique('transaction_id')
                ->sum(fn (Transaction $t) => (float) $t->gross_amount);
            $plausible = abs($paid - (float) $order->total) <= self::TOLERANCE;

            $status = $plausible ? Transaction::RECONCILIATION_MATCHED : Transaction::RECONCILIATION_MISMATCH;
            $this->link($group, $order->id, $status);

            $plausible ? $matched += $paymentCount : $mismatch += $paymentCount;
        }

        return compact('matched', 'mismatch', 'unmatched');
    }

    /**
     * Links every transaction of the order group to the pretix order, but sets a
     * reconciliation status only on the payment rows - ledger events (holds/
     * withdrawals) are linked for the deep-link but are not themselves "matched"
     * or "unmatched" (status stays null).
     */
    private function link(Collection $transactions, ?int $orderId, string $status): void
    {
        foreach ($transactions as $transaction) {
            $transaction->forceFill([
                'pretix_order_id' => $orderId,
                'reconciliation_status' => $transaction->isLedgerEvent() ? null : $status,
            ])->save();
        }
    }
}
