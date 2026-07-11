<?php

namespace App\Services\Pretix;

use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Models\Transaction;

/**
 * Books paid pretix orders with non-PayPal payment methods (bank transfer,
 * box office, …) as transactions, so the billing towards the club covers ALL
 * revenue, not just PayPal. PayPal-paid orders are skipped by default: those
 * already arrive through the PayPal sync and booking them here would double-
 * count (the per-connection import_paypal_orders toggle can override that for
 * connections without a PayPal sync).
 *
 * Bank-transfer orders carry no fee in pretix, but our transfer handling costs
 * a flat per-transaction fee (PretixConnection::bank_transfer_fee_cents,
 * default 20ct) - it is booked as fee_amount so Netto reflects it.
 */
class PretixTransactionBooker
{
    /**
     * @param  callable(string, array<string, int|string>): void|null  $onProgress
     * @param  \DateTimeInterface|null  $since  only (re-)book orders whose local
     *         record changed after this moment - unchanged orders are already
     *         booked correctly (idempotent upsert), so skipping them keeps the
     *         run O(changes) instead of O(all orders)
     * @return array{booked: int, updated: int, refunds: int, skipped_paypal: int, skipped_unpaid: int}
     */
    public function book(PretixConnection $connection, ?callable $onProgress = null, ?\DateTimeInterface $since = null): array
    {
        $progress = $onProgress ?? fn (string $m, array $p = []) => null;

        $booked = 0;
        $updated = 0;
        $refunds = 0;
        $skippedPaypal = 0;
        $skippedUnpaid = 0;

        $orders = PretixOrder::query()
            ->where('pretix_connection_id', $connection->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->get();

        foreach ($orders as $order) {
            if ($order->isPaypal() && ! $connection->import_paypal_orders) {
                $skippedPaypal++;

                continue;
            }

            $dedupeKey = hash('sha256', "pretix|{$connection->id}|{$order->event_slug}|{$order->order_code}");

            if ($order->status !== 'p') {
                // Never booked -> nothing to do. Previously booked but no longer
                // paid -> mirror the status so it stands out, and book its refunds
                // so the balance nets out (rows are never deleted).
                $mirrored = Transaction::query()->where('dedupe_key', $dedupeKey)
                    ->update(['transaction_status' => self::mapStatus($order->status)]);

                if ($mirrored > 0) {
                    $refunds += $this->bookRefunds($connection, $order);
                }

                $skippedUnpaid++;

                continue;
            }

            // Per user decision (2026-07-11): "manual" orders are hand-confirmed
            // bank-transfer receipts at HSP, so they carry the transfer fee too.
            // Never charge the fee on zero-total orders (free tickets).
            $provider = strtolower((string) $order->payment_provider);
            $isBankTransfer = str_contains($provider, 'banktransfer') || $provider === 'manual';
            $gross = (float) $order->total;
            $fee = ($isBankTransfer && $gross > 0) ? -$connection->bankTransferFee() : 0.0;

            $transaction = Transaction::updateOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'paypal_account_id' => null,
                    'transaction_id' => "PRETIX-{$order->event_slug}-{$order->order_code}",
                    'transaction_status' => self::mapStatus($order->status),
                    'transaction_initiation_date' => $order->raw_payload['payment_date'] ?? $order->order_datetime,
                    'gross_amount' => $gross,
                    'fee_amount' => $fee,
                    'net_amount' => round($gross + $fee, 2),
                    'currency' => $order->currency,
                    'payer_email' => $order->email,
                    'payment_method_type' => $order->payment_provider ?: 'unbekannt',
                    'instrument_type' => 'pretix',
                    'subject' => "pretix-Bestellung {$order->order_code} ({$order->payment_provider})",
                    // Same scheme as PayPal's custom field so parsing, filters and
                    // event-assignment rules work identically for these rows.
                    'custom_field' => 'Order ' . strtoupper($order->event_slug) . '-' . $order->order_code,
                    'raw_payload' => ['source' => 'pretix', 'connection_id' => $connection->id],
                    'raw_hash' => hash('sha256', json_encode($order->raw_payload)),
                    'imported_at' => now(),
                    'last_seen_at' => now(),
                    'pretix_order_id' => $order->id,
                    'reconciliation_status' => Transaction::RECONCILIATION_MATCHED,
                ],
            );

            $transaction->wasRecentlyCreated ? $booked++ : $updated++;

            $refunds += $this->bookRefunds($connection, $order);
        }

        $progress("Nicht-PayPal-Zahlungen verbucht: {$booked} neu, {$updated} aktualisiert, {$refunds} Erstattungen ({$skippedPaypal} PayPal-Bestellungen übersprungen, {$skippedUnpaid} unbezahlt).");

        return [
            'booked' => $booked,
            'updated' => $updated,
            'refunds' => $refunds,
            'skipped_paypal' => $skippedPaypal,
            'skipped_unpaid' => $skippedUnpaid,
        ];
    }

    /**
     * Books every completed ("done") pretix refund of the order as its own
     * negative transaction, so refunded bank-transfer money leaves the billing
     * figures again. Only called when the order's payment itself is booked -
     * otherwise a refund row without its payment row would skew the balance.
     * Refunds carry no transfer fee. Idempotent via the refund's local_id.
     *
     * @return int number of refunds booked (created or updated)
     */
    private function bookRefunds(PretixConnection $connection, PretixOrder $order): int
    {
        $count = 0;

        foreach ($order->raw_payload['refunds'] ?? [] as $refund) {
            if (($refund['state'] ?? null) !== 'done') {
                continue;
            }

            $amount = (float) ($refund['amount'] ?? 0);

            if ($amount <= 0) {
                continue;
            }

            $localId = $refund['local_id'] ?? md5(json_encode($refund));
            $dedupeKey = hash('sha256', "pretix-refund|{$connection->id}|{$order->event_slug}|{$order->order_code}|{$localId}");

            Transaction::updateOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'paypal_account_id' => null,
                    'transaction_id' => "PRETIX-R{$localId}-{$order->event_slug}-{$order->order_code}",
                    'transaction_status' => 'S',
                    'transaction_initiation_date' => $refund['execution_date'] ?? $refund['created'] ?? $order->order_datetime,
                    'gross_amount' => -$amount,
                    'fee_amount' => 0,
                    'net_amount' => -$amount,
                    'currency' => $order->currency,
                    'payer_email' => $order->email,
                    'payment_method_type' => $refund['provider'] ?? $order->payment_provider,
                    'instrument_type' => 'pretix',
                    'subject' => "pretix-Erstattung zu Bestellung {$order->order_code}",
                    'custom_field' => 'Order ' . strtoupper($order->event_slug) . '-' . $order->order_code,
                    'raw_payload' => ['source' => 'pretix-refund', 'connection_id' => $connection->id, 'refund' => $refund],
                    'raw_hash' => hash('sha256', json_encode($refund)),
                    'imported_at' => now(),
                    'last_seen_at' => now(),
                    'pretix_order_id' => $order->id,
                    'reconciliation_status' => Transaction::RECONCILIATION_MATCHED,
                ],
            );

            $count++;
        }

        return $count;
    }

    /**
     * pretix order status -> the app's PayPal-style status letters, so badges
     * and filters stay uniform: p(aid)->S, n(pending)->P, c(anceled)->V,
     * e(xpired)->D.
     */
    private static function mapStatus(?string $status): ?string
    {
        return match ($status) {
            'p' => 'S',
            'n' => 'P',
            'c' => 'V',
            'e' => 'D',
            default => $status,
        };
    }
}
