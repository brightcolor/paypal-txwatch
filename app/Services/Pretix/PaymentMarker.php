<?php

namespace App\Services\Pretix;

use App\Models\Event;
use App\Models\PretixOrder;
use App\Models\PretixPaymentConfirmation;

/**
 * Marks a pretix order paid because money arrived - and writes down why.
 *
 * THE ONE PLACE THAT DECIDES. Both sources come through here: a row in the books
 * and an entry straight from the bank pull. Two implementations of "may this order
 * be marked paid" would be two sets of rules, and the one that gets forgotten is
 * the one that writes to a customer's order.
 *
 * EVERY ATTEMPT LEAVES A ROW, including the refusals. Confirming sends the customer
 * their tickets; "the automation did nothing" has to be as answerable afterwards as
 * "the automation did this", and the old error column was overwritten by the next
 * run.
 *
 * THE GATE IS THE EVENT'S SWITCH, off by default. Nothing is written to pretix for
 * an event whose `auto_mark_paid` is not explicitly on.
 */
class PaymentMarker
{
    /** Cents, not fractions of them: pretix and the bank both count exactly. */
    private const TOLERANCE = 0.01;

    /**
     * Tries to mark the order behind this money as paid.
     *
     * Never throws for an ordinary refusal - a refusal is an outcome, and the caller
     * is a scheduled pull that must not stop at the first order it may not touch.
     */
    public function mark(PaymentEvidence $evidence, bool $automatic = true): PretixPaymentConfirmation
    {
        if (blank($evidence->orderCode)) {
            return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_SKIPPED,
                PretixPaymentConfirmation::REASON_NO_ORDER, 'Kein Bestellcode am Umsatz.');
        }

        // MONEY OUT IS NEVER A PAYMENT. A refund carries the same order code, and
        // confirming on one would mark an order paid at the moment it was refunded.
        if ($evidence->amount <= 0) {
            return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_SKIPPED,
                PretixPaymentConfirmation::REASON_AMOUNT, 'Abbuchung – eine Erstattung zahlt nichts.');
        }

        $order = $this->findOrder($evidence);

        if (! $order) {
            return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_SKIPPED,
                PretixPaymentConfirmation::REASON_NO_ORDER,
                sprintf('Zu „%s" gibt es keine eindeutige Bestellung in TxWatch.', $evidence->orderCode));
        }

        /*
         * ONE CONFIRMATION PER ORDER, EVER. The double-payment case makes this real
         * rather than theoretical: two credits carrying the same code would otherwise
         * confirm twice, and the second confirmation books money the order does not
         * owe. Checked against the record, not against a flag on the transaction, so
         * it also holds across the two sources.
         */
        if ($this->alreadyConfirmed($order)) {
            return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_SKIPPED,
                PretixPaymentConfirmation::REASON_ALREADY,
                'Für diese Bestellung wurde bereits ein Geldeingang gemeldet.', $order);
        }

        $event = Event::query()->where('pretix_event_slug', $order->event_slug)->first();

        // No event means no switch, so the automation has nothing to stand on. A
        // person clicking confirm does not need one.
        if ($automatic && ! $event) {
            return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_SKIPPED,
                PretixPaymentConfirmation::REASON_NO_EVENT,
                sprintf('Kein Event mit dem Slug „%s".', $order->event_slug), $order);
        }

        /*
         * THE SWITCH GATES THE AUTOMATION, NOT THE PERSON. It answers "may TxWatch do
         * this on its own"; someone clicking confirm has already answered that for
         * this one order. Refusing a deliberate click because a background rule is
         * off would be a switch that means something different from what it says.
         */
        if ($automatic && ! $event->auto_mark_paid) {
            return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_SKIPPED,
                PretixPaymentConfirmation::REASON_SWITCH_OFF,
                sprintf('Event „%s": automatische Meldung ist ausgeschaltet.', $event->name), $order, $event);
        }

        if ($order->status !== 'n') {
            return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_SKIPPED,
                PretixPaymentConfirmation::REASON_NOT_OPEN,
                sprintf('Bestellung steht auf „%s", nicht offen.', $order->status), $order, $event);
        }

        // The order's own total has to fit before pretix is asked anything at all -
        // a purpose can quote a code without the money belonging to it.
        if ($order->total !== null && abs((float) $order->total - $evidence->amount) > self::TOLERANCE) {
            return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_SKIPPED,
                PretixPaymentConfirmation::REASON_AMOUNT,
                sprintf(
                    'Bestellung lautet über %s, eingegangen sind %s.',
                    number_format((float) $order->total, 2, ',', '.'),
                    number_format($evidence->amount, 2, ',', '.'),
                ), $order, $event);
        }

        if (! $order->connection) {
            return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_SKIPPED,
                PretixPaymentConfirmation::REASON_NO_ORDER, 'Die pretix-Verbindung fehlt.', $order, $event);
        }

        $client = new PretixClient($order->connection);
        $payment = $client->pendingBankPayment($order->event_slug, $order->order_code);

        if (! $payment) {
            return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_SKIPPED,
                PretixPaymentConfirmation::REASON_NO_PAYMENT,
                'pretix führt keine offene Überweisungs-Zahlung zu dieser Bestellung.', $order, $event);
        }

        /*
         * The amount is checked TWICE against two different numbers: the order total
         * above, and here the pending payment pretix actually holds. They can differ
         * - a part payment, a fee, an order changed after the transfer - and only the
         * second one is what would be confirmed.
         */
        if (abs((float) $payment['amount'] - $evidence->amount) > self::TOLERANCE) {
            return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_SKIPPED,
                PretixPaymentConfirmation::REASON_AMOUNT,
                sprintf(
                    'Offene Zahlung in pretix lautet über %s, eingegangen sind %s.',
                    number_format((float) $payment['amount'], 2, ',', '.'),
                    number_format($evidence->amount, 2, ',', '.'),
                ), $order, $event, $payment['local_id']);
        }

        $result = $client->confirmPayment($order->event_slug, $order->order_code, (int) $payment['local_id']);

        if (! ($result['success'] ?? false)) {
            return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_FAILED,
                PretixPaymentConfirmation::REASON_API, (string) ($result['message'] ?? 'Unbekannter Fehler.'),
                $order, $event, $payment['local_id']);
        }

        /*
         * The local copy follows immediately instead of waiting for the next pretix
         * sync. Otherwise the journal keeps showing "offen - zu buchen" and the badge
         * keeps counting an order that is settled - and every screen would disagree
         * with pretix until the next import.
         */
        $order->forceFill(['status' => 'p'])->save();

        return $this->record($evidence, $automatic, PretixPaymentConfirmation::OUTCOME_CONFIRMED,
            PretixPaymentConfirmation::REASON_OK, (string) ($result['message'] ?? 'In pretix als bezahlt bestätigt.'),
            $order, $event, $payment['local_id']);
    }

    /**
     * The order behind a code - by connection and slug when known, otherwise by code.
     *
     * AMBIGUITY IS A REFUSAL, not a pick. pretix order codes are unique per organizer,
     * not across them; with a second connection the same code can exist twice, and
     * confirming the wrong one would mark a stranger's order paid.
     */
    private function findOrder(PaymentEvidence $evidence): ?PretixOrder
    {
        $query = PretixOrder::query()->with('connection')
            ->whereRaw('UPPER(order_code) = ?', [mb_strtoupper((string) $evidence->orderCode)]);

        if ($evidence->connectionId !== null) {
            $query->where('pretix_connection_id', $evidence->connectionId);
        }

        if (filled($evidence->eventSlug)) {
            $query->where('event_slug', $evidence->eventSlug);
        }

        $orders = $query->limit(2)->get();

        return $orders->count() === 1 ? $orders->first() : null;
    }

    /**
     * The most recent record for this very transaction, or null.
     *
     * Keyed on the transaction, not on the order: the question being answered is
     * "did we already say this about THIS money", and two credits on one order have
     * to be able to say different things.
     */
    private function lastRecordFor(PaymentEvidence $evidence): ?PretixPaymentConfirmation
    {
        $query = PretixPaymentConfirmation::query();

        if ($evidence->bankTransactionId !== null) {
            $query->where('bank_transaction_id', $evidence->bankTransactionId);
        } elseif ($evidence->journalEntryId !== null) {
            $query->where('journal_entry_id', $evidence->journalEntryId);
        } else {
            // Nothing identifies it - then nothing can be deduplicated either.
            return null;
        }

        return $query->orderByDesc('id')->first();
    }

    private function alreadyConfirmed(PretixOrder $order): bool
    {
        return PretixPaymentConfirmation::query()
            ->where('outcome', PretixPaymentConfirmation::OUTCOME_CONFIRMED)
            ->where('pretix_connection_id', $order->pretix_connection_id)
            ->where('event_slug', $order->event_slug)
            ->where('order_code', $order->order_code)
            ->exists();
    }

    private function record(
        PaymentEvidence $evidence,
        bool $automatic,
        string $outcome,
        string $reason,
        string $message,
        ?PretixOrder $order = null,
        ?Event $event = null,
        ?int $localId = null,
    ): PretixPaymentConfirmation {
        /*
         * THE SAME ANSWER TWICE IS NOT A SECOND RECORD. The pull runs four times a
         * day over three days of overlap, so every unconfirmable entry would produce
         * about a dozen identical "the switch is off" rows per day and bury the ones
         * that say something. Only a CHANGED answer is written - which is exactly
         * when there is something to read.
         */
        if ($existing = $this->lastRecordFor($evidence)) {
            if ($existing->outcome === $outcome && $existing->reason === $reason) {
                return $existing;
            }
        }

        return PretixPaymentConfirmation::create([
            'pretix_connection_id' => $order?->pretix_connection_id ?? $evidence->connectionId,
            'event_slug' => $order?->event_slug ?? $evidence->eventSlug,
            'order_code' => $order?->order_code ?? $evidence->orderCode,
            'event_id' => $event?->id,
            'bank_transaction_id' => $evidence->bankTransactionId,
            'journal_entry_id' => $evidence->journalEntryId,
            'source' => $evidence->source,
            'amount' => $evidence->amount,
            'currency' => $evidence->currency,
            'purpose' => $evidence->purpose,
            'counterparty_name' => $evidence->counterpartyName,
            'booked_on' => $evidence->bookedOn,
            'outcome' => $outcome,
            'reason' => $reason,
            'message' => $message,
            // Empty for the automation, and that emptiness is the statement: nobody
            // decided this, a rule did.
            'user_id' => $automatic ? null : auth()->id(),
            'automatic' => $automatic,
            'pretix_payment_local_id' => $localId,
            'at' => now(),
        ]);
    }
}
