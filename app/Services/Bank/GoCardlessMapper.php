<?php

namespace App\Services\Bank;

/**
 * Maps a GoCardless Bank Account Data transaction into the same normalized
 * entry shape the CAMT/MT940 parser produces, so both feed the identical
 * import + reconcile pipeline.
 */
class GoCardlessMapper
{
    /**
     * @param  array<int, array<string, mixed>>  $transactions
     * @return array<int, array<string, mixed>>
     */
    public function map(array $transactions): array
    {
        return array_values(array_filter(array_map([$this, 'one'], $transactions)));
    }

    /** @param array<string, mixed> $t */
    private function one(array $t): ?array
    {
        $amount = $t['transactionAmount']['amount'] ?? null;
        if ($amount === null) {
            return null;
        }
        $amount = (float) $amount; // already signed (negative = debit)

        $isCredit = $amount > 0;
        $counterName = $isCredit
            ? ($t['debtorName'] ?? null)
            : ($t['creditorName'] ?? null);
        $counterIban = $isCredit
            ? ($t['debtorAccount']['iban'] ?? null)
            : ($t['creditorAccount']['iban'] ?? null);

        $purpose = $t['remittanceInformationUnstructured']
            ?? (isset($t['remittanceInformationUnstructuredArray'])
                ? implode(' ', (array) $t['remittanceInformationUnstructuredArray'])
                : null);

        return [
            'booked_on' => $t['bookingDate'] ?? null,
            'valued_on' => $t['valueDate'] ?? ($t['bookingDate'] ?? null),
            'amount' => round($amount, 2),
            'currency' => $t['transactionAmount']['currency'] ?? 'EUR',
            'purpose' => $purpose,
            'counterparty_name' => $counterName,
            'counterparty_iban' => $counterIban,
            'end_to_end_id' => $t['endToEndId'] ?? ($t['transactionId'] ?? ($t['internalTransactionId'] ?? null)),
            // Bank's own stable id -> best dedupe key across polls.
            'bank_ref' => $t['transactionId'] ?? ($t['internalTransactionId'] ?? null),
            'source_format' => 'gocardless',
        ];
    }
}
