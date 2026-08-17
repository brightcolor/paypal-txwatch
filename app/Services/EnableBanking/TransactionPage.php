<?php

namespace App\Services\EnableBanking;

/**
 * The result of a transaction query: the rows, plus whether a cap cut them off.
 *
 * A VALUE OBJECT AND NOT AN ARRAY, for exactly one reason: `$truncated` must be
 * impossible to drop on the floor. Returning a plain list makes "how many did I
 * get" the only visible fact, and "was that all of them" gets lost - which turns
 * an incomplete pull into what looks like a complete one. In a set of books that
 * is the expensive kind of mistake.
 */
class TransactionPage
{
    /**
     * @param  array<int, array<string, mixed>>  $transactions
     * @param  bool  $truncated  a cap bit; there is more at the bank than is in here
     * @param  int  $pages  how many requests it took (for the log, and for spotting runaways)
     */
    public function __construct(
        public readonly array $transactions,
        public readonly bool $truncated,
        public readonly int $pages,
    ) {
    }

    public function count(): int
    {
        return count($this->transactions);
    }

    /**
     * The sentence for the UI - or null when everything came through.
     *
     * Phrased so it names the consequence, not the mechanism: "a limit of 2000
     * applied" tells an operator nothing about what to do. "There is more, pull a
     * shorter period" does.
     */
    public function truncationNotice(): ?string
    {
        if (! $this->truncated) {
            return null;
        }

        return sprintf(
            'Achtung: Es wurden %d Umsätze geholt, und damit die Obergrenze erreicht – bei der Bank '
            . 'liegen mehr. Bitte einen kürzeren Zeitraum abrufen, sonst fehlen Buchungen.',
            $this->count()
        );
    }
}
