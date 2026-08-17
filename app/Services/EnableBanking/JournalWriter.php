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
    public function __construct(private readonly PurposeMatcher $matcher)
    {
    }

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

        // Loaded once, not per entry: the lookup runs over the same set of pending
        // orders for every line.
        $orders = $this->pendingOrders();

        foreach ($entries as $entry) {
            $hash = self::hash($entry);

            $match = $this->matcher->match(
                $entry['purpose'] ?? null,
                $orders,
                isset($entry['amount']) ? (float) $entry['amount'] : null,
            );

            // Only an EXACT hit becomes an assignment; fuzzy stays a proposal in
            // `match_candidates`. See PurposeMatcher for why.
            $code = $match['code'];

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
                    'match_method' => $match['method'],
                    'match_score' => $match['score'],
                    'match_candidates' => $match['candidates'],
                    'match_haystack' => $match['haystack'],
                    'raw' => $entry,
                    'pulled_at' => now(),
                ]),
            );

            if ($journal->wasRecentlyCreated) {
                $recorded++;
                $this->protocol($journal, 'pulled', $entry, $match);
            } else {
                $known++;
                /*
                 * A KNOWN ENTRY IS RE-EXAMINED, and that is the point of doing it
                 * on every pull: an order that only became due after the last pull
                 * turns "nothing found" into a proposal. Without this the very
                 * transfer that arrived before its order was imported would stay
                 * unmatched forever.
                 *
                 * Written only when something actually changed - four pulls a day
                 * would otherwise fill the protocol with "still the same".
                 */
                $this->reexamine($journal, $match);
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
     * Writes one protocol line for a freshly recorded entry.
     *
     * TWO EVENTS, NOT ONE: what the pull delivered, and what the recognition made
     * of it. Merged into a single line the two would be indistinguishable later -
     * and the question "did the bank change the text or did our matching change" is
     * exactly the one that comes up.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $match
     */
    private function protocol(EnableBankingJournalEntry $journal, string $kind, array $entry, array $match): void
    {
        $journal->events()->create([
            'kind' => $kind,
            'message' => sprintf(
                'Abgerufen: %s über %s EUR%s. Verwendungszweck %d Zeichen.',
                (float) ($entry['amount'] ?? 0) < 0 ? 'Abbuchung' : 'Eingang',
                number_format(abs((float) ($entry['amount'] ?? 0)), 2, ',', '.'),
                filled($entry['counterparty_name'] ?? null) ? ' von ' . $entry['counterparty_name'] : '',
                mb_strlen((string) ($entry['purpose'] ?? '')),
            ),
            'context' => [
                'bank_ref' => $entry['bank_ref'] ?? null,
                'booked_on' => $entry['booked_on'] ?? null,
            ],
            'at' => now(),
        ]);

        $journal->events()->create($this->matchEvent($match));
    }

    /**
     * Re-examines a known entry - and only writes when the picture changed.
     *
     * @param  array<string, mixed>  $match
     */
    private function reexamine(EnableBankingJournalEntry $journal, array $match): void
    {
        $before = [$journal->match_method, $journal->pretix_order_code, $journal->match_score];
        $after = [$match['method'], $match['code'], $match['score']];

        if ($before === $after) {
            return;
        }

        $journal->forceFill([
            'pretix_order_code' => $match['code'] ?? $journal->pretix_order_code,
            'match_method' => $match['method'],
            'match_score' => $match['score'],
            'match_candidates' => $match['candidates'],
            'match_haystack' => $match['haystack'],
        ])->save();

        $event = $this->matchEvent($match);
        $event['kind'] = 'changed';
        $event['message'] = 'Erneut geprüft, Ergebnis geändert: ' . $event['message'];

        $journal->events()->create($event);
    }

    /**
     * The protocol line for a recognition result.
     *
     * Says WHY, not just what: which string was searched, which stage hit, and with
     * a proposal how many candidates there were. A protocol that only records the
     * outcome answers no question that anyone actually asks.
     *
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function matchEvent(array $match): array
    {
        $codes = implode(', ', array_column($match['candidates'], 'code'));

        $message = match ($match['method']) {
            PurposeMatcher::EXACT => sprintf('Bestellnummer %s im Verwendungszweck gefunden.', $codes),
            PurposeMatcher::NORMALISED => sprintf(
                'Bestellnummer %s erst nach Entfernen von Trenn- und Leerzeichen gefunden.',
                $codes,
            ),
            PurposeMatcher::FUZZY => sprintf(
                'Keine Bestellnummer wörtlich gefunden. %d Vorschlag/Vorschläge mit einem abweichenden '
                . 'Zeichen: %s. Wird NICHT automatisch zugeordnet.',
                count($match['candidates']),
                $codes,
            ),
            default => 'Keine offene Bestellnummer im Verwendungszweck erkennbar.',
        };

        return [
            'kind' => match ($match['method']) {
                PurposeMatcher::EXACT, PurposeMatcher::NORMALISED => 'matched',
                PurposeMatcher::FUZZY => 'suggested',
                default => 'unmatched',
            },
            'message' => $message,
            'context' => [
                'method' => $match['method'],
                'score' => $match['score'],
                // The searched string, so nobody has to guess later which
                // normalisation ran at the time.
                'haystack' => $match['haystack'],
                'candidates' => $match['candidates'],
            ],
            'at' => now(),
        ];
    }

    /**
     * The orders still waiting for a bank transfer, with their amount.
     *
     * DELIBERATELY NOT A REGULAR EXPRESSION over the purpose text. A pattern like
     * "five capitals" matches half of every SEPA reference - measured on the real
     * data it would have taken `MV070` out of a Sparkasse fee reference. Searching
     * for codes that ACTUALLY EXIST and are ACTUALLY open cannot invent a match.
     *
     * The AMOUNT comes along because it is the strongest corroboration for a fuzzy
     * proposal: in both real typo cases it narrowed 1025 orders down to exactly
     * one.
     *
     * @return array<string, array{code: string, total: float|null}>
     */
    private function pendingOrders(): array
    {
        if (! $this->pretixAvailable()) {
            return [];
        }

        $orders = [];

        PretixOrder::query()
            ->where('status', 'n')
            ->whereNotNull('order_code')
            ->select(['order_code', 'payment_provider', 'total'])
            ->cursor()
            ->each(function (PretixOrder $order) use (&$orders): void {
                $provider = strtolower((string) $order->payment_provider);

                if (! str_contains($provider, 'banktransfer') && $provider !== 'manual') {
                    return;
                }

                $code = (string) $order->order_code;

                // Under four characters a code is too short to appear in a purpose
                // by intent rather than by accident.
                if (mb_strlen($code) >= 4) {
                    $orders[mb_strtoupper($code)] = [
                        'code' => $code,
                        'total' => $order->total !== null ? (float) $order->total : null,
                    ];
                }
            });

        return $orders;
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
