<?php

namespace App\Services\Bank;

/**
 * Maps phpFinTS statement transactions (Fhp\Model\StatementOfAccount\Transaction)
 * into the same normalized entry shape the CAMT/MT940 parser produces, so FinTS
 * feeds the identical import + reconcile pipeline (BankStatementImporter).
 *
 * Duck-typed on purpose (no strict Transaction type hint) so it stays unit
 * testable with lightweight stubs that expose the same getters.
 */
class FintsMapper
{
    /**
     * @param  iterable<object>  $transactions  phpFinTS transaction objects
     * @return array<int, array<string, mixed>>
     */
    public function map(iterable $transactions): array
    {
        $out = [];

        foreach ($transactions as $t) {
            $mapped = $this->one($t);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function one(object $t): ?array
    {
        $magnitude = (float) $t->getAmount();

        // phpFinTS reports the amount as an unsigned magnitude plus a
        // credit/debit marker - make it signed like every other source
        // (negative = money out).
        $isDebit = $t->getCreditDebit() === \Fhp\Model\StatementOfAccount\Transaction::CD_DEBIT;
        $amount = $isDebit ? -abs($magnitude) : abs($magnitude);

        $booked = $t->getBookingDate() ?: $t->getDate();
        $valued = $t->getValutaDate() ?: $booked;

        $purpose = trim($t->getMainDescription());
        $name = trim($t->getName());
        $iban = trim($t->getAccountNumber());
        $eref = trim($t->getEndToEndID());

        return [
            'booked_on' => $booked?->format('Y-m-d'),
            'valued_on' => ($valued ?: $booked)?->format('Y-m-d'),
            'amount' => round($amount, 2),
            'currency' => 'EUR',
            'purpose' => $purpose !== '' ? $purpose : null,
            'counterparty_name' => $name !== '' ? $name : null,
            'counterparty_iban' => $iban !== '' ? $iban : null,
            'end_to_end_id' => $eref !== '' ? $eref : null,
            // FinTS/MT940 has no stable per-transaction id, so dedupe relies on
            // the importer's composite hash (date + amount + purpose + IBAN).
            'bank_ref' => null,
            'source_format' => 'fints',
        ];
    }
}
