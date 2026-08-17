<?php

namespace Tests\Feature\Bank;

use App\Services\EnableBanking\PurposeMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The recognition, held against the shapes that really occur.
 *
 * EVERY PATTERN HERE WAS MEASURED on 166 real credits (17.08.2026) - the order
 * codes are invented, the SHAPE of the purposes is not. That is the whole value of
 * this test: it does not check what I imagined customers write, it checks what they
 * actually wrote.
 */
class PurposeMatcherTest extends TestCase
{
    /** @return array<string, array{code: string, total: float|null}> */
    private function orders(array $codes): array
    {
        $out = [];

        foreach ($codes as $code => $total) {
            $out[strtoupper($code)] = ['code' => $code, 'total' => $total];
        }

        return $out;
    }

    #[DataProvider('realShapes')]
    public function test_the_shapes_that_really_occur(string $purpose, string $expected, string $method): void
    {
        $result = (new PurposeMatcher())->match($purpose, $this->orders([$expected => 63.80]), 63.80);

        $this->assertSame($method, $result['method'], 'Erkennungsstufe weicht ab bei: ' . $purpose);
        $this->assertSame($expected, $result['code']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function realShapes(): iterable
    {
        // The everyday case: code appended after a prefix, with separators.
        yield 'Präfix mit Bindestrichen' => ['GAG-WISMAR-2026-JHNQ9', 'JHNQ9', PurposeMatcher::EXACT];

        // Mixed case - the bank passes through what the customer typed.
        yield 'gemischte Schreibung' => ['GAG-Wismar-2026-FUXZ7', 'FUXZ7', PurposeMatcher::EXACT];

        // Glued on without any separator.
        yield 'ohne Trennzeichen' => ['ACFRIENDS2026RN9FK', 'RN9FK', PurposeMatcher::EXACT];

        /*
         * Stray space from the bank's own field wrapping. Measured in the wild as
         * `GAG-Wismar -2026-77ZSL` and `ACFRIEND- S2026ZSFT9` - there the space fell
         * outside the code, so an exact hit still worked.
         */
        yield 'Leerzeichen daneben' => ['GAG-Wismar -2026-77ZSL', '77ZSL', PurposeMatcher::EXACT];
        yield 'Leerzeichen vor dem Code' => ['ACFRIEND- S2026ZSFT9', 'ZSFT9', PurposeMatcher::EXACT];

        /*
         * THE CASE NORMALISING IS FOR: the space lands INSIDE the code. Did not
         * occur in the measured corpus, but the wrapping that produces those spaces
         * clearly does - `Be trag`, `76793 0` in the same statements.
         */
        yield 'Leerzeichen IM Code' => ['GAG-WISMAR-2026-JHN Q9', 'JHNQ9', PurposeMatcher::NORMALISED];
        yield 'Umbruch im Code' => ['Bestellung ZSF T9 bezahlt', 'ZSFT9', PurposeMatcher::NORMALISED];
    }

    /**
     * The code twice, once truncated - measured as
     * `ACFRIENDS2026WEGS Bestellnummer 7WEGS vom 15.8.26`.
     *
     * The complete code must win, not the four-character fragment in front of it.
     */
    public function test_the_complete_code_wins_over_a_fragment(): void
    {
        $result = (new PurposeMatcher())->match(
            'ACFRIENDS2026WEGS Bestellnummer 7WEGS vom 15.8.26',
            $this->orders(['7WEGS' => 219.73]),
            219.73,
        );

        $this->assertSame('7WEGS', $result['code']);
        $this->assertSame(PurposeMatcher::EXACT, $result['method']);
    }

    /**
     * ONE WRONG CHARACTER ONLY PROPOSES - measured: customer wrote `RSYBQ` for
     * `R9YBQ`, an S instead of a 9.
     *
     * `code` stays null on purpose. Filling it would make a guess look like a
     * finding, and every consumer reading `code` would book on it.
     */
    public function test_a_wrong_character_only_suggests(): void
    {
        $result = (new PurposeMatcher())->match(
            'FCASPIEL-RSYBQ',
            $this->orders(['R9YBQ' => 33.00]),
            33.00,
        );

        $this->assertSame(PurposeMatcher::FUZZY, $result['method']);
        $this->assertNull($result['code'], 'Ein unscharfer Treffer darf NICHT automatisch zugeordnet werden.');
        $this->assertSame('R9YBQ', $result['candidates'][0]['code']);
        $this->assertTrue($result['candidates'][0]['amount_matches']);
        // A confusion pair plus a matching amount is the strongest proposal there
        // is - and still below the 95 of an exact hit.
        $this->assertSame(90, $result['candidates'][0]['score']);
    }

    /**
     * A MISSING CHARACTER as well - measured: `9JCW` written for `G9JCW`.
     */
    public function test_a_missing_character_only_suggests(): void
    {
        $result = (new PurposeMatcher())->match(
            'ACFRIENDS20269JCW',
            $this->orders(['G9JCW' => 87.89]),
            87.89,
        );

        $this->assertSame(PurposeMatcher::FUZZY, $result['method']);
        $this->assertNull($result['code']);
        $this->assertSame('G9JCW', $result['candidates'][0]['code']);
    }

    /**
     * The amount separates two otherwise equally plausible proposals.
     *
     * Both are one edit away; only one is for this sum. Without the amount the list
     * would be a coin toss.
     */
    public function test_the_amount_ranks_the_proposals(): void
    {
        $result = (new PurposeMatcher())->match(
            'Zahlung RSYBQ',
            $this->orders(['R9YBQ' => 33.00, 'RSYBX' => 999.00]),
            33.00,
        );

        $this->assertSame(PurposeMatcher::FUZZY, $result['method']);
        $this->assertSame('R9YBQ', $result['candidates'][0]['code']);
        $this->assertGreaterThan($result['candidates'][1]['score'], $result['candidates'][0]['score']);
    }

    /**
     * WHAT MUST NOT MATCH. These are the 22 unmatched of the real corpus: PayPal
     * payouts and outgoing invoices. A recognition that finds a ticket order in them
     * is worse than one that finds nothing.
     */
    #[DataProvider('mustNotMatch')]
    public function test_foreign_references_match_nothing(string $purpose): void
    {
        $result = (new PurposeMatcher())->match(
            $purpose,
            $this->orders(['JHNQ9' => 63.80, 'ZSFT9' => 87.89]),
            2926.96,
        );

        $this->assertNull($result['code'], 'Fremde Referenz wurde zugeordnet: ' . $purpose);
        $this->assertSame([], $result['candidates']);
    }

    /** @return iterable<string, array{string}> */
    public static function mustNotMatch(): iterable
    {
        yield 'PayPal-Auszahlung' => ['PP.6710.PP/ABBUCHUNG VOM PAYPAL-KONTO'];
        yield 'eigene Rechnung' => ['RNR RE-20261301 Datum 03.08.2026 Be trag 2.368,10 Kto. 76793 0'];
        yield 'Gebührenreferenz' => ['Rechnung SPARKASSE MECKLENBURG- NOR DWEST 20260520-MV070-00014971092'];
    }

    /**
     * THE RESIDUAL RISK, WRITTEN DOWN RATHER THAN WISHED AWAY.
     *
     * Order codes are five characters. If one of them happens to occur in a bank's
     * own reference - `MV070` really does sit in a Sparkasse fee invoice - an exact
     * match finds it, and rightly so: the string IS there. No delimiter rule helps,
     * because legitimate codes are glued on without one (`ACFRIENDS2026RN9FK`).
     *
     * What separates the two is the AMOUNT, and that is why every candidate carries
     * `amount_matches`. A found code whose amount does not fit is a finding to look
     * at, not a booking to make - and the journal shows exactly that.
     *
     * This test does not assert the collision away. It pins that the collision is
     * RECOGNISABLE.
     */
    public function test_an_exact_hit_with_a_wrong_amount_is_marked(): void
    {
        $result = (new PurposeMatcher())->match(
            'Rechnung SPARKASSE MECKLENBURG- NOR DWEST 20260520-MV070-00014971092',
            $this->orders(['MV070' => 100.00]),
            5.95,
        );

        // The string is there, so it is found - denying that would be a lie.
        $this->assertSame(PurposeMatcher::EXACT, $result['method']);
        $this->assertSame('MV070', $result['code']);

        // But the amount says it is not this order, and that is visible.
        $this->assertFalse(
            $result['candidates'][0]['amount_matches'],
            'Eine Kollision muss am Betrag erkennbar bleiben, sonst wird sie stillschweigend gebucht.',
        );
        $this->assertSame(100.00, $result['candidates'][0]['order_total']);
    }

    /** Without open orders nothing is recognised - and it does not throw. */
    public function test_without_orders_nothing_happens(): void
    {
        $result = (new PurposeMatcher())->match('GAG-WISMAR-2026-JHNQ9', [], 63.80);

        $this->assertSame(PurposeMatcher::NONE, $result['method']);
        $this->assertNull($result['code']);
        $this->assertSame([], $result['candidates']);
    }

    /** An empty purpose is a normal case, not an error. */
    public function test_an_empty_purpose_is_no_error(): void
    {
        foreach ([null, '', '   '] as $purpose) {
            $result = (new PurposeMatcher())->match($purpose, $this->orders(['JHNQ9' => 63.80]), 63.80);

            $this->assertSame(PurposeMatcher::NONE, $result['method']);
        }
    }

    /**
     * The searched string is recorded - it is what answers "why was nothing found".
     */
    public function test_the_searched_string_is_reported(): void
    {
        $result = (new PurposeMatcher())->match('GAG-Wismar -2026-77ZSL', $this->orders(['77ZSL' => 63.80]), 63.80);

        $this->assertSame('GAGWISMAR202677ZSL', $result['haystack']);
    }

    /** Umlauts become letter pairs instead of vanishing and shifting the windows. */
    public function test_umlauts_do_not_shift_the_windows(): void
    {
        $this->assertSame('FUERZSFT9', PurposeMatcher::normalise('für ZSFT9'));
    }
}
