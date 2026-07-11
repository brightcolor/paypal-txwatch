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
     * @return array{booked: int, updated: int, skipped_paypal: int, skipped_unpaid: int}
     */
    public function book(PretixConnection $connection, ?callable $onProgress = null): array
    {
        $progress = $onProgress ?? fn (string $m, array $p = []) => null;

        $booked = 0;
        $updated = 0;
        $skippedPaypal = 0;
        $skippedUnpaid = 0;

        $orders = PretixOrder::query()
            ->where('pretix_connection_id', $connection->id)
            ->get();

        foreach ($orders as $order) {
            if ($order->isPaypal() && ! $connection->import_paypal_orders) {
                $skippedPaypal++;

                continue;
            }

            $dedupeKey = hash('sha256', "pretix|{$connection->id}|{$order->event_slug}|{$order->order_code}");

            if ($order->status !== 'p') {
                // Never booked -> nothing to do. Previously booked but no longer
                // paid -> mirror the status so it stands out; amounts stay (rows
                // are never deleted - mark irrelevant manually if needed).
                Transaction::query()->where('dedupe_key', $dedupeKey)
                    ->update(['transaction_status' => self::mapStatus($order->status)]);
                $skippedUnpaid++;

                continue;
            }

            $isBankTransfer = str_contains(strtolower((string) $order->payment_provider), 'banktransfer');
            $fee = $isBankTransfer ? -$connection->bankTransferFee() : 0.0;
            $gross = (float) $order->total;

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
        }

        $progress("Nicht-PayPal-Zahlungen verbucht: {$booked} neu, {$updated} aktualisiert ({$skippedPaypal} PayPal-Bestellungen übersprungen, {$skippedUnpaid} unbezahlt).");

        return [
            'booked' => $booked,
            'updated' => $updated,
            'skipped_paypal' => $skippedPaypal,
            'skipped_unpaid' => $skippedUnpaid,
        ];
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
