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

        // Loaded once, not per entry: the lookup runs over the same set of known
        // orders for every line.
        $orders = $this->knownOrders();

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
                    'pretix_order_status' => self::statusOf($match),
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
                $this->flagSecondCredit($journal);
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
        /*
         * THE STATE IS PART OF THE COMPARISON. An order that got paid between two
         * pulls changes nothing about the code but everything about what is to be
         * done - and that change belongs in the protocol.
         */
        $before = [$journal->match_method, $journal->pretix_order_code, $journal->match_score, $journal->pretix_order_status];
        $after = [$match['method'], $match['code'], $match['score'], self::statusOf($match)];

        if ($before === $after) {
            return;
        }

        $journal->forceFill([
            'pretix_order_code' => $match['code'] ?? $journal->pretix_order_code,
            'pretix_order_status' => self::statusOf($match) ?? $journal->pretix_order_status,
            'match_method' => $match['method'],
            'match_score' => $match['score'],
            'match_candidates' => $match['candidates'],
            'match_haystack' => $match['haystack'],
        ])->save();

        $event = $this->matchEvent($match);
        $event['kind'] = 'changed';
        $event['message'] = 'Erneut geprüft, Ergebnis geändert: ' . $event['message'];

        $journal->events()->create($event);

        // An entry that only NOW carries a code can only now collide with another.
        $this->flagSecondCredit($journal);
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

        // What FOLLOWS from a hit depends on the order's state, and that is what the
        // protocol has to say - not merely that something was found.
        $first = $match['candidates'][0] ?? null;
        $folge = match (true) {
            $first === null => '',
            ($first['order_open'] ?? false) => ' Die Bestellung ist offen – hier wäre etwas zu buchen.',
            ($first['order_status'] ?? null) === 'p' => ' Die Bestellung ist bereits bezahlt, es ist nichts zu tun.',
            default => sprintf(' Zustand der Bestellung: %s.', $first['order_status'] ?? 'unbekannt'),
        };

        $message = match ($match['method']) {
            PurposeMatcher::EXACT => sprintf('Bestellnummer %s im Verwendungszweck gefunden.%s', $codes, $folge),
            PurposeMatcher::NORMALISED => sprintf(
                'Bestellnummer %s erst nach Entfernen von Trenn- und Leerzeichen gefunden.%s',
                $codes,
                $folge,
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
     * EVERY known order with amount and state - not just the open ones.
     *
     * MEASURED WHY: of 1025 orders, 986 are already paid and 5 are open. Looking at
     * open ones only meant almost every incoming transfer came out as "nothing
     * recognised", indistinguishable from a real gap - and that was the normal case,
     * not the exception. With all of them the journal can say "belongs to X, already
     * paid" instead of shrugging.
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
     * @return array<string, array{code: string, total: float|null, status: string|null, provider: string}>
     */
    private function knownOrders(): array
    {
        if (! $this->pretixAvailable()) {
            return [];
        }

        $orders = [];

        PretixOrder::query()
            ->whereNotNull('order_code')
            ->select(['order_code', 'payment_provider', 'total', 'status'])
            ->cursor()
            ->each(function (PretixOrder $order) use (&$orders): void {
                $code = (string) $order->order_code;

                // Under four characters a code is too short to appear in a purpose
                // by intent rather than by accident.
                if (mb_strlen($code) < 4) {
                    return;
                }

                /*
                 * THE PAYMENT METHOD NO LONGER FILTERS, only the state does.
                 *
                 * It used to restrict to banktransfer/manual, which is right for
                 * BOOKING - a PayPal order is not settled by a transfer. But for
                 * RECOGNISING it was wrong: a transfer that carries the code of a
                 * PayPal order is worth knowing about, and silently dropping it left
                 * the entry looking like an unexplained gap.
                 */
                $orders[mb_strtoupper($code)] = [
                    'code' => $code,
                    'total' => $order->total !== null ? (float) $order->total : null,
                    'status' => $order->status,
                    'provider' => strtolower((string) $order->payment_provider),
                ];
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
     * The state of the recognised order, or null when nothing was recognised.
     *
     * @param  array<string, mixed>  $match
     */
    private static function statusOf(array $match): ?string
    {
        if (blank($match['code'] ?? null)) {
            return null;
        }

        return $match['candidates'][0]['order_status'] ?? null;
    }

    /**
     * TWO CREDITS ON ONE ORDER - the only honest sign of a double payment.
     *
     * MEASURED, AND THE FIRST ATTEMPT WAS WRONG. It asked whether the order was
     * already paid and the amount fitted; against the 166 real credits that flagged
     * 140, because an order is marked paid PRECISELY BECAUSE that transfer arrived.
     * It described the normal case and would have put a warning on five out of six
     * entries - a column nobody reads after the first day.
     *
     * A second credit on the same order flags 1 of 166: order XDUBJ, 5,00 EUR and
     * 58,30 EUR on the same day against a total of 58,30.
     *
     * BOTH ENTRIES GET MARKED, not only the newer one. A pair is only readable as a
     * pair; marking one leaves the other looking like an ordinary payment, and
     * whoever opens that one wonders what the warning on its sibling meant.
     *
     * DELIBERATELY NOT COMPARED AGAINST bank_transactions. The same payment can sit
     * there from a hand-imported statement file, and flagging that would report a
     * duplicate where there is one payment seen twice. Journal entries are
     * deduplicated by `import_hash`, so two of them sharing an order code really are
     * two different transactions.
     */
    private function flagSecondCredit(EnableBankingJournalEntry $journal): void
    {
        // A refund carries the same code and is the opposite of a double payment.
        if (blank($journal->pretix_order_code) || (float) $journal->amount <= 0) {
            return;
        }

        $sibling = EnableBankingJournalEntry::query()
            ->where('pretix_order_code', $journal->pretix_order_code)
            ->where('amount', '>', 0)
            ->where('id', '!=', $journal->id)
            ->orderBy('id')
            ->first();

        if ($sibling === null) {
            return;
        }

        foreach ([$sibling, $journal] as $betroffen) {
            if ((bool) $betroffen->possible_double_payment) {
                continue;
            }

            $betroffen->forceFill(['possible_double_payment' => true])->save();

            $betroffen->events()->create([
                'kind' => 'changed',
                'message' => sprintf(
                    'MÖGLICHE DOPPELZAHLUNG: Auf Bestellung %s gibt es einen zweiten Geldeingang '
                    . '(%s EUR am %s und %s EUR am %s). Bitte prüfen, ob eine Erstattung fällig ist.',
                    $journal->pretix_order_code,
                    number_format((float) $sibling->amount, 2, ',', '.'),
                    $sibling->booked_on?->format('d.m.Y') ?? '?',
                    number_format((float) $journal->amount, 2, ',', '.'),
                    $journal->booked_on?->format('d.m.Y') ?? '?',
                ),
                'context' => [
                    'order_code' => $journal->pretix_order_code,
                    'entries' => [$sibling->id, $journal->id],
                ],
                'at' => now(),
            ]);
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
