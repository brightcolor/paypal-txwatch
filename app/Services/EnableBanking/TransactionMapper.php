<?php

namespace App\Services\EnableBanking;

/**
 * Maps Enable Banking transactions into the same normalized entry shape the
 * CAMT/MT940 parser and the FinTS mapper produce, so this path feeds the
 * identical import + reconcile pipeline (BankStatementImporter).
 *
 * Four things here are easy to get wrong and expensive when wrong:
 *
 *  1. THE SIGN. `transaction_amount.amount` is frequently an UNSIGNED magnitude
 *     with `credit_debit_indicator` carrying the direction - but some banks send
 *     a signed amount as well. Applying the indicator to an already-negative
 *     number flips it back to positive, and an expense lands in the books as
 *     income. abs() first, then the indicator, always.
 *
 *  2. WHO THE COUNTERPARTY IS. For money coming in it is the DEBTOR; for money
 *     going out the CREDITOR. Reading whichever field happens to be filled puts
 *     the account holder's own name in the counterparty column of half the rows.
 *
 *  3. PENDING ROWS ARE NOT BOOKED. `status = PDNG` entries change amount, text
 *     or vanish entirely. Importing them creates rows that later mutate under a
 *     reconciliation that already matched them. Only BOOK comes in - and what
 *     was skipped is counted, not silently dropped.
 *
 *  4. THE PURPOSE IS A LIST. `remittance_information` is an array of lines; a
 *     naive cast yields "Array" and the invoice number in it - the thing the
 *     reconciler matches on - is lost.
 */
class TransactionMapper
{
    /** Money in. */
    private const CREDIT = 'CRDT';

    /** Booked, as opposed to PDNG (pending). */
    private const BOOKED = 'BOOK';

    /**
     * @param  array<int, array<string, mixed>>  $transactions
     * @return array{entries: array<int, array<string, mixed>>, skipped_pending: int}
     */
    public function map(array $transactions): array
    {
        $entries = [];
        $pending = 0;

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            if (! $this->isBooked($transaction)) {
                $pending++;

                continue;
            }

            $entry = $this->one($transaction);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return ['entries' => $entries, 'skipped_pending' => $pending];
    }

    /**
     * Only booked entries.
     *
     * A MISSING status COUNTS AS BOOKED. Not every bank sends the field, and
     * treating "absent" as pending would silently import nothing at all from
     * those banks - the worst outcome, because the pull reports success.
     */
    private function isBooked(array $transaction): bool
    {
        $status = $transaction['status'] ?? null;

        return ! is_string($status) || strtoupper($status) === self::BOOKED;
    }

    /** @return array<string, mixed>|null */
    private function one(array $transaction): ?array
    {
        $amount = $this->amount($transaction);

        if ($amount === null) {
            return null;
        }

        $isCredit = $this->isCredit($transaction);

        $booked = $this->date($transaction, ['booking_date', 'value_date', 'transaction_date']);
        $valued = $this->date($transaction, ['value_date', 'booking_date', 'transaction_date']);

        // Counterparty: debtor when money comes in, creditor when it goes out.
        $party = $isCredit ? 'debtor' : 'creditor';
        $name = $this->text($transaction[$party]['name'] ?? null);
        $iban = $this->text($transaction[$party . '_account']['iban'] ?? null);

        return [
            'booked_on' => $booked,
            'valued_on' => $valued ?: $booked,
            'amount' => round($isCredit ? abs($amount) : -abs($amount), 2),
            'currency' => $this->text($transaction['transaction_amount']['currency'] ?? null) ?? 'EUR',
            'purpose' => $this->purpose($transaction),
            'counterparty_name' => $name,
            'counterparty_iban' => $iban,
            'end_to_end_id' => $this->text($transaction['end_to_end_id'] ?? null),
            /*
             * `entry_reference` is the bank's own id for the line and is stable
             * where it exists - it makes the importer's dedupe hash exact instead
             * of composite. Where it is absent the composite still carries.
             */
            'bank_ref' => $this->text($transaction['entry_reference'] ?? null),
            'source_format' => 'enablebanking',
        ];
    }

    /**
     * The magnitude, unsigned.
     *
     * Returned as a float from a STRING on purpose: the API sends amounts as
     * decimal strings ("12.34"), and casting through float is the only place a
     * cent could be lost - so it happens once, here, and round() in the caller
     * pins it to two decimals.
     */
    private function amount(array $transaction): ?float
    {
        $raw = $transaction['transaction_amount']['amount'] ?? null;

        if (! is_string($raw) && ! is_numeric($raw)) {
            return null;
        }

        return abs((float) $raw);
    }

    /**
     * Direction.
     *
     * DEFAULTS TO DEBIT when the indicator is missing, and that asymmetry is
     * intentional: an expense wrongly booked as income inflates revenue and is
     * the error that survives a review, because nobody questions money arriving.
     */
    private function isCredit(array $transaction): bool
    {
        $indicator = $transaction['credit_debit_indicator'] ?? null;

        return is_string($indicator) && strtoupper($indicator) === self::CREDIT;
    }

    /**
     * First usable date out of a preference list, as Y-m-d.
     *
     * @param  array<int, string>  $keys
     */
    private function date(array $transaction, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $transaction[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return (new \DateTimeImmutable($value))->format('Y-m-d');
            } catch (\Exception) {
                // An unparseable date is not worth aborting the whole row over;
                // the next key in the list usually carries.
                continue;
            }
        }

        return null;
    }

    /**
     * The remittance information, joined into one line.
     *
     * Both shapes occur in the wild - a list of lines and a single string - and
     * the reconciler searches this text for invoice numbers, so losing it costs
     * the automatic match.
     */
    private function purpose(array $transaction): ?string
    {
        $raw = $transaction['remittance_information'] ?? null;

        if (is_string($raw)) {
            return $this->text($raw);
        }

        if (is_array($raw)) {
            $joined = implode(' ', array_filter(
                array_map(fn ($line) => is_string($line) ? trim($line) : '', $raw),
                fn (string $line) => $line !== '',
            ));

            return $this->text($joined);
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
