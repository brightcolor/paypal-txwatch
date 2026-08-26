<?php

namespace App\Services\Bank;

use App\Models\BankTransaction;
use App\Models\PretixOrder;
use App\Models\PretixPaymentConfirmation;
use App\Services\Pretix\PaymentEvidence;
use App\Services\Pretix\PaymentMarker;

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
 *
 * THE DECISION ITSELF LIVES IN PaymentMarker, not here. It is shared with the
 * Enable Banking journal, which can trigger the same confirmation straight from a
 * pull - and two copies of "may this order be marked paid" would be two sets of
 * rules, with the forgotten one writing to a customer's order. This class still
 * owns the bank row's own status columns; the reasoning and the record do not
 * belong to it.
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

            /*
             * THE SWITCH MOVED FROM THE CONNECTION TO THE EVENT. All or nothing for
             * an entire organizer could not exclude a finished event or one whose
             * payments are handled elsewhere. PaymentMarker reads it - and records
             * the refusal, so "why did nothing happen" has an answer too.
             */
            $this->confirm($bank, automatic: true);
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
    public function confirm(BankTransaction $bank, bool $automatic = false): array
    {
        if (blank($bank->pretix_order_code) || blank($bank->pretix_connection_id)) {
            return $this->fail($bank, 'Kein zugeordneter pretix-Auftrag.');
        }

        $confirmation = app(PaymentMarker::class)->mark(
            PaymentEvidence::fromBankTransaction($bank),
            automatic: $automatic,
        );

        if ($confirmation->succeeded()) {
            $bank->update([
                'pretix_report_status' => BankTransaction::REPORT_REPORTED,
                'pretix_reported_at' => now(),
                'pretix_report_error' => null,
            ]);

            return ['success' => true, 'message' => (string) $confirmation->message];
        }

        /*
         * A REFUSAL IS NOT A FAILURE. "The event's switch is off" is the automation
         * working as configured; marking the bank row FAILED for it would light up a
         * red error on every credit of every event that is not automated - and bury
         * the ones that really went wrong. Only a genuine failure is recorded as one.
         */
        if ($confirmation->outcome === PretixPaymentConfirmation::OUTCOME_SKIPPED && $automatic) {
            return ['success' => false, 'message' => $confirmation->reasonText()];
        }

        return $this->fail($bank, (string) ($confirmation->message ?: $confirmation->reasonText()));
    }

    /** @return array{success: bool, message: string} */
    private function fail(BankTransaction $bank, string $message): array
    {
        $bank->update(['pretix_report_status' => BankTransaction::REPORT_FAILED, 'pretix_report_error' => $message]);

        return ['success' => false, 'message' => $message];
    }
}
