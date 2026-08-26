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
            $confirmation = $this->marker->mark(PaymentEvidence::fromJournalEntry($entry));

            match ($confirmation->outcome) {
                PretixPaymentConfirmation::OUTCOME_CONFIRMED => $counts['confirmed']++,
                PretixPaymentConfirmation::OUTCOME_FAILED => $counts['failed']++,
                default => $counts['skipped']++,
            };

            if ($confirmation->succeeded()) {
                $this->closeEntry($entry, $confirmation);

                continue;
            }

            /*
             * A refusal only reaches the journal's own protocol when it is NEW.
             * PaymentMarker returns the existing record unchanged when the answer has
             * not moved, and a line saying "still switched off" four times a day is
             * noise in the one place that has to stay readable.
             */
            if ($confirmation->wasRecentlyCreated) {
                $entry->events()->create([
                    'kind' => 'changed',
                    'message' => 'Nicht an pretix gemeldet: ' . $confirmation->reasonText(),
                    'context' => [
                        'confirmation_id' => $confirmation->id,
                        'reason' => $confirmation->reason,
                    ],
                    'at' => now(),
                ]);
            }
        }

        return $counts;
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
        $entry->forceFill(['pretix_order_status' => 'p'])->save();

        $entry->events()->create([
            'kind' => 'matched',
            'message' => sprintf(
                'An pretix gemeldet: Bestellung %s auf BEZAHLT gesetzt (%s EUR). %s',
                $confirmation->order_code,
                number_format((float) $confirmation->amount, 2, ',', '.'),
                $confirmation->reasonText(),
            ),
            'context' => [
                'confirmation_id' => $confirmation->id,
                'event_slug' => $confirmation->event_slug,
                'pretix_payment_local_id' => $confirmation->pretix_payment_local_id,
            ],
            'at' => now(),
        ]);
    }
}
