<?php

namespace App\Services\EnableBanking;

/**
 * Finds the pretix order code in a bank purpose - including the ways customers
 * really write it.
 *
 * MEASURED, NOT GUESSED (17.08.2026, 166 real credits against 1025 order codes):
 *
 *   exact hit              142
 *   only after normalising   0
 *   only fuzzy (distance 1)  2
 *   nothing                 22   (PayPal payouts and invoices - correctly nothing)
 *
 * The two fuzzy ones are exactly the cases to catch: a customer wrote `RSYBQ` for
 * `R9YBQ` (S instead of 9) and `9JCW` for `G9JCW` (leading G missing).
 *
 * NORMALISING GAINED NOTHING on that corpus, and saying so matters more than
 * shipping it as a win: the stray spaces the bank injects (`GAG-Wismar -2026-…`,
 * `ACFRIEND- S2026…`, `Be trag`) happened to fall OUTSIDE the codes. It stays in
 * anyway because it costs one preg_replace and the field-wrapping that produces
 * those spaces will eventually land inside a code - but it is insurance, not a
 * measured improvement.
 *
 * A DOUBLE PAYMENT IS NOT DECIDED HERE. The first attempt asked whether the order
 * was already paid and the amount fitted. Measured against the 166 real credits
 * that flagged 140 of them - because an order is marked paid PRECISELY BECAUSE
 * that transfer arrived. The criterion described the normal case. The real signal
 * is a SECOND credit on the same order, and only JournalWriter can see it: it
 * needs the other entries, not the purpose text.
 *
 * WHY FUZZY ONLY SUGGESTS. Codes are five characters. Across 1025 of them only 3
 * pairs sit one edit apart, so the space is sparse - but the risk is not code
 * against code, it is an arbitrary five-character window of a hundred-character
 * text against 1025 codes. Both real cases were unambiguous AND corroborated by an
 * exact amount, yet money does not get booked on a guess. Exact assigns, fuzzy
 * proposes.
 */
class PurposeMatcher
{
    public const EXACT = 'exact';
    public const NORMALISED = 'normalised';
    public const FUZZY = 'fuzzy';
    public const NONE = 'none';

    /** Shortest window still worth comparing - below this everything matches everything. */
    private const MIN_WINDOW = 4;

    /**
     * Character pairs that really get mixed up on a transfer slip.
     *
     * Not needed for the match itself - generic distance 1 already catches these -
     * but a substitution from this table is far more plausible than an arbitrary
     * one, and that difference belongs in the score a human sees.
     */
    private const CONFUSABLE = [
        'S' => ['5', '9'], '5' => ['S'], '9' => ['S', 'G'], 'G' => ['6', '9'],
        'O' => ['0'], '0' => ['O', 'Q'], 'I' => ['1', 'L'], '1' => ['I', 'L'],
        'L' => ['1', 'I'], 'B' => ['8'], '8' => ['B'], '6' => ['G'],
        'Z' => ['2'], '2' => ['Z'], 'U' => ['V'], 'V' => ['U'], 'Q' => ['0'],
    ];

    /**
     * Everything but letters and digits removed, uppercased.
     *
     * Public because the protocol records it: when someone later asks why a code
     * was or was not found, the string that was actually searched has to be
     * visible, not reconstructed.
     */
    public static function normalise(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        // Umlauts first - they carry no code, but stripping them blindly would glue
        // neighbouring characters together and shift every window.
        $text = strtr($text, ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ä' => 'AE', 'ö' => 'OE', 'ü' => 'UE', 'ß' => 'SS']);

        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($text)) ?? '';
    }

    /**
     * @param  array<string, array{code: string, total: float|null, status: string|null}>  $orders  uppercase code => order
     * @return array{method: string, code: ?string, score: int, candidates: array<int, array<string, mixed>>, haystack: string}
     */
    public function match(?string $purpose, array $orders, ?float $amount = null): array
    {
        $raw = mb_strtoupper((string) $purpose);
        $haystack = self::normalise($purpose);

        $result = [
            'method' => self::NONE,
            'code' => null,
            'score' => 0,
            'candidates' => [],
            'haystack' => $haystack,
        ];

        if ($haystack === '' || $orders === []) {
            return $result;
        }

        /*
         * STAGE ONE, exact in the untouched text. Deliberately first and separate
         * from the normalised pass: a hit here needs no explanation, and it is what
         * 142 of 166 real transfers do.
         */
        $exact = $this->allContaining($raw, $orders, self::EXACT, 100, $amount);

        if ($exact !== []) {
            return $this->best(self::EXACT, $exact, $haystack);
        }

        // Stage two: exact after the separators and stray spaces are gone.
        $normalised = $this->allContaining($haystack, $orders, self::NORMALISED, 95, $amount);

        if ($normalised !== []) {
            return $this->best(self::NORMALISED, $normalised, $haystack);
        }

        /*
         * STAGE THREE: one character wrong, missing or extra. Collects EVERY
         * candidate rather than stopping at the first - the number of them is the
         * whole point. One candidate is a proposal worth showing; four mean the
         * purpose does not identify an order and a human has to decide.
         */
        $candidates = [];

        foreach ($orders as $code => $order) {
            $hit = $this->nearestWindow($haystack, (string) $code);

            if ($hit === null) {
                continue;
            }

            $candidates[] = self::candidate(
                $code,
                $order,
                self::FUZZY,
                $this->fuzzyScore((string) $code, $hit, $amount, $order),
                $amount,
                $hit,
            );
        }

        if ($candidates === []) {
            return $result;
        }

        /*
         * OPEN ORDERS FIRST, then score. A proposal for a settled order is
         * information; a proposal for an open one is work. Sorting by score alone
         * would bury the actionable one under the merely interesting.
         */
        usort($candidates, function (array $a, array $b) {
            return [$b['order_open'], $b['score']] <=> [$a['order_open'], $a['score']];
        });

        return [
            'method' => self::FUZZY,
            /*
             * NO `code` EVEN WITH A SINGLE CANDIDATE. Filling it would make a
             * proposal look like a finding, and every consumer that reads `code`
             * would book on a guess. The suggestion lives in `candidates`, and
             * taking it is a decision someone makes.
             */
            'code' => null,
            'score' => $candidates[0]['score'],
            'candidates' => array_slice($candidates, 0, 5),
            'haystack' => $haystack,
        ];
    }

    /**
     * Every order whose code literally occurs in $text.
     *
     * COLLECTS INSTEAD OF RETURNING THE FIRST, and that is not pedantry: with all
     * orders in play - 1025 of them, 986 already paid - the first hit in an
     * arbitrary iteration order could be a settled order while an open one also
     * matches. Whichever came first out of the database would then decide, and the
     * same purpose could resolve differently after an import.
     *
     * @param  array<string, array{code: string, total: float|null, status: string|null}>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function allContaining(string $text, array $orders, string $method, int $score, ?float $amount): array
    {
        $found = [];

        foreach ($orders as $code => $order) {
            if (str_contains($text, (string) $code)) {
                $found[] = self::candidate((string) $code, $order, $method, $score, $amount, (string) $code);
            }
        }

        return $found;
    }

    /**
     * The most relevant of several literal hits: open before settled, then amount.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{method: string, code: ?string, score: int, candidates: array<int, array<string, mixed>>, haystack: string}
     */
    private function best(string $method, array $candidates, string $haystack): array
    {
        usort($candidates, function (array $a, array $b) {
            return [$b['order_open'], $b['amount_matches']] <=> [$a['order_open'], $a['amount_matches']];
        });

        return [
            'method' => $method,
            // A literal hit IS an assignment, no matter what state the order is in -
            // whether anything follows from it is decided by `order_status`.
            'code' => $candidates[0]['code'],
            'score' => $candidates[0]['score'],
            'candidates' => array_slice($candidates, 0, 5),
            'haystack' => $haystack,
        ];
    }
    /**
     * The window of $haystack that is one edit away from $code - or null.
     *
     * Windows of length ±1 as well, because a missing character shortens the text
     * and an extra one lengthens it; comparing only equal length would miss exactly
     * the "one character missing" case the customer produced.
     */
    private function nearestWindow(string $haystack, string $code): ?string
    {
        $len = strlen($code);
        $max = strlen($haystack);

        for ($offset = 0; $offset < $max; $offset++) {
            foreach ([$len, $len - 1, $len + 1] as $width) {
                if ($width < self::MIN_WINDOW || $offset + $width > $max) {
                    continue;
                }

                $window = substr($haystack, $offset, $width);

                if (levenshtein($window, $code) === 1) {
                    return $window;
                }
            }
        }

        return null;
    }

    /**
     * How plausible a fuzzy candidate is, 0-90.
     *
     * Never reaches the 95/100 of an exact hit, so a proposal can never look as
     * good as a finding.
     *
     * @param  array{code: string, total: float|null, status: string|null}  $order
     */
    private function fuzzyScore(string $code, string $window, ?float $amount, array $order): int
    {
        $score = 50;

        // A substitution from the confusion table is a typo; an arbitrary one is
        // more likely a different code entirely.
        if ($this->isConfusable($code, $window)) {
            $score += 20;
        }

        /*
         * THE AMOUNT IS THE STRONGEST CORROBORATION, and in both real cases it
         * pointed at exactly one order. A matching amount plus a one-character typo
         * is a different quality of evidence than a typo alone.
         */
        if ($amount !== null && $order['total'] !== null && abs((float) $order['total'] - abs($amount)) <= 0.01) {
            $score += 20;
        }

        return min(90, $score);
    }

    /** Is the single difference one of the pairs people really mix up? */
    private function isConfusable(string $code, string $window): bool
    {
        if (strlen($code) !== strlen($window)) {
            return false;
        }

        for ($i = 0, $len = strlen($code); $i < $len; $i++) {
            if ($code[$i] === $window[$i]) {
                continue;
            }

            return in_array($window[$i], self::CONFUSABLE[$code[$i]] ?? [], true);
        }

        return false;
    }

    /**
     * @param  array{code: string, total: float|null, status: string|null}  $order
     * @return array<string, mixed>
     */
    private static function candidate(
        string $code,
        array $order,
        string $method,
        int $score,
        ?float $amount,
        string $found,
    ): array {
        $amountMatches = $amount !== null && $order['total'] !== null
            && abs((float) $order['total'] - abs($amount)) <= 0.01;

        $status = $order['status'] ?? null;
        $open = $status === 'n';

        return [
            'code' => $code,
            'method' => $method,
            'score' => $score,
            'found' => $found,
            'order_total' => $order['total'],
            'amount_matches' => $amountMatches,
            /*
             * THE STATUS DECIDES WHETHER THERE IS ANYTHING TO DO. Measured on the
             * real data: 986 of 1025 orders are already paid, 5 are open. Without
             * this the journal reported "no assignment" for almost everything and
             * a real gap looked exactly like a settled order.
             */
            'order_status' => $status,
            'order_open' => $open,
        ];
    }
}
