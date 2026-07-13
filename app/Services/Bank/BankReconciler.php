<?php

namespace App\Services\Bank;

use App\Models\BankTransaction;
use App\Models\Transaction;
use App\Services\CustomFieldParser;
use Illuminate\Support\Carbon;

/**
 * Auto-matches imported bank credits against the app's own records:
 *  1. PayPal payouts (T04xx/T20xx): a payout leaving the PayPal balance shows
 *     up on the bank a few days later as a credit of the same amount.
 *  2. pretix bank transfers: booked as pretix transactions; the order code
 *     usually appears in the bank purpose text.
 * Never links one app transaction to two bank lines. Idempotent: already
 * matched/ignored bank rows are skipped.
 */
class BankReconciler
{
    private const TOLERANCE = 0.01;
    private const PAYOUT_WINDOW_DAYS = 6;

    /** @return int number of bank rows newly matched */
    public function reconcile(): int
    {
        $matched = 0;

        $unmatched = BankTransaction::query()
            ->where('reconciliation_status', BankTransaction::STATUS_UNMATCHED)
            ->where('amount', '>', 0) // only incoming credits are matched
            ->orderBy('valued_on')
            ->get();

        // Transaction ids already claimed by any bank row - never reuse.
        $claimed = BankTransaction::query()->whereNotNull('matched_transaction_id')
            ->pluck('matched_transaction_id')->flip();

        foreach ($unmatched as $bank) {
            $match = $this->matchPayout($bank, $claimed) ?? $this->matchPretix($bank, $claimed);

            if ($match) {
                $bank->update([
                    'reconciliation_status' => BankTransaction::STATUS_MATCHED,
                    'matched_transaction_id' => $match['transaction']->id,
                    'match_method' => $match['method'],
                ]);
                $claimed->put($match['transaction']->id, true);
                $matched++;
            }
        }

        return $matched;
    }

    /** @param \Illuminate\Support\Collection<int,mixed> $claimed */
    private function matchPayout(BankTransaction $bank, $claimed): ?array
    {
        $amount = (float) $bank->amount;
        $ref = $bank->valued_on ?? $bank->booked_on;

        $payout = Transaction::query()
            ->payouts()
            ->currentRevision()
            ->excludingIrrelevant()
            ->whereRaw('ABS(ABS(gross_amount) - ?) <= ?', [$amount, self::TOLERANCE])
            ->when($ref, fn ($q) => $q->whereBetween('transaction_initiation_date', [
                Carbon::parse($ref)->subDays(self::PAYOUT_WINDOW_DAYS)->startOfDay(),
                Carbon::parse($ref)->addDays(self::PAYOUT_WINDOW_DAYS)->endOfDay(),
            ]))
            ->get()
            ->first(fn (Transaction $t) => ! $claimed->has($t->id));

        return $payout ? ['transaction' => $payout, 'method' => BankTransaction::METHOD_PAYOUT] : null;
    }

    /** @param \Illuminate\Support\Collection<int,mixed> $claimed */
    private function matchPretix(BankTransaction $bank, $claimed): ?array
    {
        if (blank($bank->purpose)) {
            return null;
        }

        $amount = (float) $bank->amount;
        $haystack = mb_strtoupper($bank->purpose);

        // Among the pretix transfers with a matching amount, pick the one whose
        // own order code (or event reference) is mentioned in the bank purpose -
        // reliable without assuming a fixed reference format on the bank side.
        $candidate = Transaction::query()
            ->where('instrument_type', 'pretix')
            ->currentRevision()
            ->excludingIrrelevant()
            ->where('gross_amount', '>', 0)
            ->whereRaw('ABS(gross_amount - ?) <= ?', [$amount, self::TOLERANCE])
            ->get()
            ->first(function (Transaction $t) use ($claimed, $haystack) {
                if ($claimed->has($t->id)) {
                    return false;
                }
                $code = CustomFieldParser::orderNumber($t->custom_field);

                return $code !== null && mb_strlen($code) >= 4 && str_contains($haystack, mb_strtoupper($code));
            });

        return $candidate ? ['transaction' => $candidate, 'method' => BankTransaction::METHOD_PRETIX] : null;
    }
}
