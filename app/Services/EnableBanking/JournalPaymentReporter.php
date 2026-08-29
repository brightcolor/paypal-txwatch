<?php

namespace App\Services\EnableBanking;

use App\Models\EnableBankingJournalEntry;
use App\Models\PretixPaymentConfirmation;
use App\Services\Pretix\PaymentEvidence;
use App\Services\Pretix\PaymentMarker;

/**
 * Reports the money from a bank pull to pretix, for events that allow it.
 *
 * WHY THIS RUNS IN JOURNAL MODE TOO. Marking an order paid in pretix and booking
 * money into the accounts are two different things. The journal exists so nothing
 * lands in the books before it has been looked at; the request here was for the
 * other one - that a transfer arriving settles its order without anyone typing.
 * Holding that back until the books are switched over would tie an outward-facing
 * automation to an accounting decision that has nothing to do with it.
 *
 * NOTHING HAPPENS WITHOUT AN EXPLICIT PER-EVENT SWITCH, off by default. The rule
 * that writes to a stranger's order is only ever armed one event at a time.
 *
 * THE HAND AND THE AUTOMATION TAKE THE SAME PATH. `reportEntry()` is what the
 * button in the journal calls and what the scheduled run calls, one entry at a
 * time. The two differ in exactly two arguments - who triggered it, and whether a
 * deviating amount was accepted - and in nothing else: a separate manual path would
 * be a second set of rules for writing into a guest's order, and it is the forgotten
 * one that does the damage.
 */
class JournalPaymentReporter
{
    public function __construct(private readonly PaymentMarker $marker)
    {
    }

    /**
     * Walks the entries that could settle an order and tries each one.
     *
     * @return array{confirmed: int, skipped: int, failed: int}
     */
    public function report(): array
    {
        $counts = ['confirmed' => 0, 'skipped' => 0, 'failed' => 0];

        /*
         * ONLY ENTRIES WHOSE ORDER IS STILL OPEN. That is the whole candidate set -
         * on the real data 5 orders out of 1025 - so four pulls a day cost almost
         * nothing. Everything already settled would be refused anyway, one pretix
         * request later.
         */
        $entries = EnableBankingJournalEntry::query()
            ->whereNotNull('pretix_order_code')
            ->where('amount', '>', 0)
            ->where('pretix_order_status', 'n')
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $confirmation = $this->reportEntry($entry);

            match ($confirmation->outcome) {
                PretixPaymentConfirmation::OUTCOME_CONFIRMED => $counts['confirmed']++,
                PretixPaymentConfirmation::OUTCOME_FAILED => $counts['failed']++,
                default => $counts['skipped']++,
            };
        }

        return $counts;
    }

    /**
     * ONE entry, reported to pretix - by the scheduled pull or by a person.
     *
     * `$orderCode` is how a person names the order themselves: the recognition only
     * ever reads the purpose text, and it cannot know that the guest quoted the code
     * with a typo or left it out entirely. Passing it does NOT skip any check - the
     * order still has to exist, be open and hold an open transfer payment in pretix.
     *
     * `$allowAmountMismatch` is refused for the automation inside PaymentMarker, so
     * it cannot leak into a scheduled run from here either.
     *
     * The outcome is returned rather than reported: the caller is a screen that has
     * to say what happened, or a run that counts.
     */
    public function reportEntry(
        EnableBankingJournalEntry $entry,
        bool $automatic = true,
        ?string $orderCode = null,
        bool $allowAmountMismatch = false,
    ): PretixPaymentConfirmation {
        $evidence = PaymentEvidence::fromJournalEntry($entry);

        if (filled($orderCode)) {
            $evidence = $evidence->withOrderCode($orderCode);
        }

        $confirmation = $this->marker->mark(
            $evidence,
            automatic: $automatic,
            allowAmountMismatch: $allowAmountMismatch,
        );

        if ($confirmation->succeeded()) {
            $this->closeEntry($entry, $confirmation);

            return $confirmation;
        }

        /*
         * A refusal only reaches the journal's own protocol when it is NEW.
         * PaymentMarker returns the existing record unchanged when the answer has
         * not moved, and a line saying "still switched off" four times a day is
         * noise in the one place that has to stay readable. A hand-triggered attempt
         * is always new there, so a refused click is never silent.
         */
        if ($confirmation->wasRecentlyCreated) {
            $entry->events()->create([
                'kind' => 'changed',
                'message' => $this->who($confirmation)
                    . 'Nicht an pretix gemeldet: ' . $confirmation->reasonText(),
                'context' => [
                    'confirmation_id' => $confirmation->id,
                    'reason' => $confirmation->reason,
                    'automatic' => $confirmation->automatic,
                    'user_id' => $confirmation->user_id,
                ],
                'at' => now(),
            ]);
        }

        return $confirmation;
    }

    /**
     * The entry follows the order it just settled.
     *
     * Without this the journal keeps showing "offen - zu buchen" and the number on
     * the menu item keeps counting an order that TxWatch itself has just marked paid
     * - every screen disagreeing with pretix until the next order import.
     */
    private function closeEntry(EnableBankingJournalEntry $entry, PretixPaymentConfirmation $confirmation): void
    {
        /*
         * A HAND ASSIGNMENT IS KEPT ONLY ONCE IT HELD. Someone naming an order that
         * pretix then confirmed has proven the assignment; a mistyped code that was
         * refused has proven nothing, and writing it onto the entry anyway would turn
         * a slip of the finger into a permanent-looking finding.
         */
        $previous = $entry->pretix_order_code;
        $handAssigned = filled($confirmation->order_code)
            && mb_strtoupper((string) $previous) !== mb_strtoupper((string) $confirmation->order_code);

        $entry->forceFill($handAssigned
            ? [
                'pretix_order_status' => 'p',
                'pretix_order_code' => $confirmation->order_code,
                // Outranks the recognition on the next pull - see JournalWriter.
                'match_method' => PurposeMatcher::MANUAL,
            ]
            : ['pretix_order_status' => 'p'])->save();

        if ($handAssigned) {
            $entry->events()->create([
                'kind' => 'changed',
                'message' => sprintf(
                    '%sVon Hand der Bestellung %s zugeordnet – die Erkennung hatte %s.',
                    $this->who($confirmation),
                    $confirmation->order_code,
                    filled($previous) ? $previous : 'nichts zugeordnet',
                ),
                'context' => [
                    'confirmation_id' => $confirmation->id,
                    'previous_order_code' => $previous,
                    'user_id' => $confirmation->user_id,
                ],
                'at' => now(),
            ]);
        }

        $entry->events()->create([
            'kind' => 'matched',
            'message' => sprintf(
                '%sAn pretix gemeldet: Bestellung %s auf BEZAHLT gesetzt (%s EUR). %s',
                $this->who($confirmation),
                $confirmation->order_code,
                number_format((float) $confirmation->amount, 2, ',', '.'),
                $confirmation->reasonText(),
            ),
            'context' => [
                'confirmation_id' => $confirmation->id,
                'event_slug' => $confirmation->event_slug,
                'pretix_payment_local_id' => $confirmation->pretix_payment_local_id,
                'automatic' => $confirmation->automatic,
                'user_id' => $confirmation->user_id,
            ],
            'at' => now(),
        ]);
    }

    /**
     * Who did this, as a sentence opener - empty for the automation.
     *
     * The name belongs in the MESSAGE, not only in a context field: the protocol is
     * read as prose, and "who marked this order paid" is the first question asked of
     * a line that sent a guest their tickets.
     */
    private function who(PretixPaymentConfirmation $confirmation): string
    {
        return $confirmation->automatic
            ? ''
            : sprintf('Von Hand durch %s: ', $confirmation->triggeredByLabel());
    }
}
