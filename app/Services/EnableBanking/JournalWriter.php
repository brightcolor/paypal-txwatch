<?php

namespace App\Services\EnableBanking;

use App\Models\EnableBankingJournalEntry;
use App\Models\PretixOrder;
use Illuminate\Support\Facades\DB;

/**
 * Records pulled transactions in the journal - and books nothing.
 *
 * WHY THIS EXISTS AT ALL instead of feeding BankStatementImporter right away: the
 * Enable Banking path is new, and the first thing it should do is prove what it
 * delivers. Anything written into bank_transactions immediately shows up in the
 * reconciliation, in the reports and in the EÜR; an interpretation error would
 * then have to be untangled out of the books instead of out of a log.
 *
 * SAME IDENTITY AS THE IMPORTER. `import_hash` is computed exactly as
 * BankStatementImporter::hash() does it. Two consequences, both wanted: repeated
 * pulls (four a day, with three days of overlap) do not duplicate anything, and a
 * later promotion cannot create a second row for a statement line that was already
 * imported from a file.
 */
class JournalWriter
{
    /**
     * @param  array<int, array<string, mixed>>  $entries  normalized, from TransactionMapper
     * @return array{recorded: int, known: int, with_order: int, dropped: int, refunds: int, kept: array<int, array<string, mixed>>}
     */
    public function record(array $entries): array
    {
        $recorded = 0;
        $known = 0;
        $withOrder = 0;
        $dropped = 0;
        $refunds = 0;
        $kept = [];

        // Loaded once, not per entry: the candidate lookup runs over the same
        // small set of pending orders for every line.
        $candidates = $this->pendingOrderCodes();

        foreach ($entries as $entry) {
            $hash = self::hash($entry);
            $code = $this->orderCodeIn((string) ($entry['purpose'] ?? ''), $candidates);

            /*
             * MONEY OUT IS DROPPED - unless it carries an order code.
             *
             * This application watches ticket money. A card fee, a petrol station,
             * a standing order: none of it belongs in the books it keeps, and
             * recording it would bury the one entry that matters under hundreds
             * that do not. Recorded, everything would still have to be read by
             * someone.
             *
             * THE EXCEPTION IS THE POINT: a debit WITH an order code is a refund
             * of exactly that order, and that is as relevant as the payment was.
             * Dropping it would leave a settled order looking paid when the money
             * went back.
             *
             * Dropped ENTIRELY rather than recorded-and-hidden: a row nobody looks
             * at is a row someone will eventually query by accident. What was
             * dropped is counted and reported, so the number is never silent.
             */
            if ((float) ($entry['amount'] ?? 0) < 0 && blank($code)) {
                $dropped++;

                continue;
            }

            $kept[] = $entry;

            if ((float) ($entry['amount'] ?? 0) < 0) {
                $refunds++;
            }

            /*
             * firstOrCreate on the unique hash, so two pulls racing (a scheduled
             * one and someone pressing "Jetzt abrufen") cannot both insert.
             */
            $journal = EnableBankingJournalEntry::firstOrCreate(
                ['import_hash' => $hash],
                array_merge($entry, [
                    'pretix_order_code' => $code,
                    'raw' => $entry,
                    'pulled_at' => now(),
                ]),
            );

            if ($journal->wasRecentlyCreated) {
                $recorded++;
            } else {
                $known++;
            }

            if (filled($code)) {
                $withOrder++;
            }
        }

        return [
            'recorded' => $recorded,
            'known' => $known,
            'with_order' => $withOrder,
            'dropped' => $dropped,
            'refunds' => $refunds,
            /*
             * The kept entries go back to the caller so the later import mode sees
             * the SAME set. One place decides relevance - otherwise the journal
             * would show one thing and the books would contain another.
             */
            'kept' => $kept,
        ];
    }

    /**
     * The order codes of orders that are still waiting for a bank transfer.
     *
     * DELIBERATELY NOT A REGULAR EXPRESSION over the purpose text. A pattern like
     * "four to six capitals" matches half of every SEPA reference - including the
     * bank's own ones. Searching for codes that ACTUALLY EXIST and are ACTUALLY
     * open cannot invent a match. The reconciliation does it exactly this way
     * (BankPretixReporter::findPendingOrder), and this stays consistent with it so
     * the journal predicts what the booking step will later do.
     *
     * @return array<string, string> uppercase code => original code
     */
    private function pendingOrderCodes(): array
    {
        if (! $this->pretixAvailable()) {
            return [];
        }

        $codes = [];

        PretixOrder::query()
            ->where('status', 'n')
            ->whereNotNull('order_code')
            ->select(['order_code', 'payment_provider'])
            ->cursor()
            ->each(function (PretixOrder $order) use (&$codes): void {
                $provider = strtolower((string) $order->payment_provider);

                if (! str_contains($provider, 'banktransfer') && $provider !== 'manual') {
                    return;
                }

                $code = (string) $order->order_code;

                // Under four characters a code is too short to appear in a purpose
                // by intent rather than by accident - same bar the reconciliation
                // uses.
                if (mb_strlen($code) >= 4) {
                    $codes[mb_strtoupper($code)] = $code;
                }
            });

        return $codes;
    }

    /** @param array<string, string> $candidates */
    private function orderCodeIn(string $purpose, array $candidates): ?string
    {
        if ($purpose === '' || $candidates === []) {
            return null;
        }

        $haystack = mb_strtoupper($purpose);

        foreach ($candidates as $upper => $original) {
            if (str_contains($haystack, $upper)) {
                return $original;
            }
        }

        return null;
    }

    /**
     * Is there a pretix table to ask at all?
     *
     * Guarded because the journal has to work on an installation without pretix
     * as well - and a missing table must not turn a successful bank pull into an
     * exception.
     */
    private function pretixAvailable(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('pretix_orders');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The identity of an entry - byte for byte as BankStatementImporter computes it.
     *
     * KEPT IN SYNC BY HAND, and that is a liability worth naming: if the importer
     * ever changes its hash, this has to follow, or a promoted entry becomes a
     * duplicate. A test holds the two against each other.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function hash(array $entry): string
    {
        return hash('sha256', implode('|', [
            $entry['end_to_end_id'] ?? '',
            $entry['bank_ref'] ?? '',
            $entry['valued_on'] ?? '',
            $entry['amount'] ?? '',
            $entry['purpose'] ?? '',
            $entry['counterparty_iban'] ?? '',
        ]));
    }
}
