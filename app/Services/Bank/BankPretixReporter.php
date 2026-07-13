<?php

namespace App\Services\Bank;

use App\Models\BankTransaction;
use App\Models\PretixOrder;
use App\Services\Pretix\PretixClient;

/**
 * Reports matched bank transfers to pretix as paid.
 *
 * A conservative matcher links an unreconciled incoming bank credit to a
 * PENDING pretix bank-transfer order (exact amount, order code present in the
 * purpose). Matches become a one-click proposal; if the owning connection has
 * auto-confirm enabled, they are confirmed in pretix immediately.
 *
 * Confirming is a WRITE action (marks the order paid, triggers the ticket
 * email), so the match must be unambiguous: exact amount + order code, only
 * pending banktransfer/manual orders, one bank line per order.
 */
class BankPretixReporter
{
    private const TOLERANCE = 0.01;

    /**
     * Scans unreconciled credits for pending pretix orders they pay off.
     * Returns the number of new proposals (auto-confirmed ones included).
     */
    public function propose(): int
    {
        $proposals = 0;

        $credits = BankTransaction::query()
            ->where('amount', '>', 0)
            ->where('reconciliation_status', BankTransaction::STATUS_UNMATCHED)
            ->where('pretix_report_status', BankTransaction::REPORT_NONE)
            ->whereNotNull('purpose')
            ->orderBy('valued_on')
            ->get();

        // Order codes already claimed by a proposed/reported bank row.
        $claimed = BankTransaction::query()
            ->whereIn('pretix_report_status', [BankTransaction::REPORT_PROPOSED, BankTransaction::REPORT_REPORTED])
            ->whereNotNull('pretix_order_code')
            ->get(['pretix_connection_id', 'pretix_order_code'])
            ->map(fn ($r) => $r->pretix_connection_id . '|' . $r->pretix_order_code)->flip();

        foreach ($credits as $bank) {
            $order = $this->findPendingOrder($bank, $claimed);
            if (! $order) {
                continue;
            }

            $bank->update([
                'pretix_connection_id' => $order->pretix_connection_id,
                'pretix_event_slug' => $order->event_slug,
                'pretix_order_code' => $order->order_code,
                'pretix_report_status' => BankTransaction::REPORT_PROPOSED,
            ]);
            $claimed->put($order->pretix_connection_id . '|' . $order->order_code, true);
            $proposals++;

            if ($order->connection?->auto_confirm_bank_transfers) {
                $this->confirm($bank);
            }
        }

        return $proposals;
    }

    /** @param \Illuminate\Support\Collection<int,mixed> $claimed */
    private function findPendingOrder(BankTransaction $bank, $claimed): ?PretixOrder
    {
        $amount = (float) $bank->amount;
        $haystack = mb_strtoupper($bank->purpose);

        return PretixOrder::query()
            ->where('status', 'n') // pending
            ->with('connection')
            ->get()
            ->first(function (PretixOrder $o) use ($amount, $haystack, $claimed) {
                $provider = strtolower((string) $o->payment_provider);
                $isBank = str_contains($provider, 'banktransfer') || $provider === 'manual';
                $code = (string) $o->order_code;

                return $isBank
                    // Strict amount check in PHP (SQLite's decimal handling
                    // makes an ABS()-in-SQL tolerance unreliable).
                    && abs((float) $o->total - $amount) <= self::TOLERANCE
                    && mb_strlen($code) >= 4
                    && str_contains($haystack, mb_strtoupper($code))
                    && ! $claimed->has($o->pretix_connection_id . '|' . $o->order_code);
            });
    }

    /**
     * Confirms one proposed bank transfer in pretix. Idempotent-ish: safe to
     * retry a failed one.
     *
     * @return array{success: bool, message: string}
     */
    public function confirm(BankTransaction $bank): array
    {
        if (blank($bank->pretix_order_code) || blank($bank->pretix_connection_id)) {
            return $this->fail($bank, 'Kein zugeordneter pretix-Auftrag.');
        }

        $order = PretixOrder::query()
            ->where('pretix_connection_id', $bank->pretix_connection_id)
            ->where('event_slug', $bank->pretix_event_slug)
            ->where('order_code', $bank->pretix_order_code)
            ->with('connection')
            ->first();

        if (! $order?->connection) {
            return $this->fail($bank, 'pretix-Bestellung/Verbindung nicht gefunden.');
        }

        $client = new PretixClient($order->connection);
        $payment = $client->pendingBankPayment($order->event_slug, $order->order_code);

        if (! $payment) {
            return $this->fail($bank, 'Keine offene Überweisungs-Zahlung in pretix (evtl. schon bezahlt/storniert).');
        }

        // Guard: only confirm if the pending amount matches the bank credit.
        if (abs((float) $payment['amount'] - (float) $bank->amount) > self::TOLERANCE) {
            return $this->fail($bank, 'Betrag der offenen Zahlung weicht ab – nicht automatisch bestätigt.');
        }

        $result = $client->confirmPayment($order->event_slug, $order->order_code, $payment['local_id']);

        if ($result['success']) {
            $bank->update([
                'pretix_report_status' => BankTransaction::REPORT_REPORTED,
                'pretix_reported_at' => now(),
                'pretix_report_error' => null,
            ]);
        } else {
            $this->fail($bank, $result['message']);
        }

        return $result;
    }

    /** @return array{success: bool, message: string} */
    private function fail(BankTransaction $bank, string $message): array
    {
        $bank->update(['pretix_report_status' => BankTransaction::REPORT_FAILED, 'pretix_report_error' => $message]);

        return ['success' => false, 'message' => $message];
    }
}
