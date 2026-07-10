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
            ->get()
            ->filter(fn (PretixOrder $o) => $o->ownMatchKey() !== null)
            ->keyBy(fn (PretixOrder $o) => $o->ownMatchKey());

        $groups = Transaction::query()
            ->whereNotNull('custom_field')
            ->where('custom_field', '<>', '')
            ->get()
            ->groupBy(fn (Transaction $t) => $t->pretixMatchKey());

        $matched = 0;
        $mismatch = 0;
        $unmatched = 0;

        foreach ($groups as $key => $group) {
            if (blank($key)) {
                continue;
            }

            /** @var PretixOrder|null $order */
            $order = $ordersByKey->get($key);

            if (! $order) {
                $this->setStatus($group, null, Transaction::RECONCILIATION_UNMATCHED);
                $unmatched += $group->count();

                continue;
            }

            // Compare the pretix order total against the PayPal amount actually
            // collected (positive gross; refunds are separate rows and handled
            // by their own accounting, not by lowering the "was it paid" check).
            $paid = (float) $group->where('gross_amount', '>', 0)->sum(fn (Transaction $t) => (float) $t->gross_amount);
            $plausible = abs($paid - (float) $order->total) <= self::TOLERANCE;

            $status = $plausible ? Transaction::RECONCILIATION_MATCHED : Transaction::RECONCILIATION_MISMATCH;
            $this->setStatus($group, $order->id, $status);

            $plausible ? $matched += $group->count() : $mismatch += $group->count();
        }

        return compact('matched', 'mismatch', 'unmatched');
    }

    private function setStatus(Collection $transactions, ?int $orderId, string $status): void
    {
        foreach ($transactions as $transaction) {
            $transaction->forceFill([
                'pretix_order_id' => $orderId,
                'reconciliation_status' => $status,
            ])->save();
        }
    }
}
