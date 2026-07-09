<?php

namespace App\Services\Sync;

use App\Models\PaypalAccount;
use Illuminate\Support\Carbon;

/**
 * Normalizes one row of a PayPal "Activity Download" CSV (already mapped
 * to our field names by the import wizard) into the same flat shape
 * TransactionNormalizer produces from the API, so both ingestion paths
 * share TransactionUpserter's idempotency logic unchanged.
 */
class CsvTransactionNormalizer
{
    /**
     * @param  array<int, string>  $rawRow  the original CSV row, keyed by column header (for raw_payload/raw_hash)
     * @param  array<string, mixed>  $mappedRow  target field => raw string value, e.g. ['gross' => '100,00', 'date' => '01.06.2026', ...]
     */
    public function normalize(PaypalAccount $account, array $rawRow, array $mappedRow): array
    {
        $gross = $this->parseAmount($mappedRow['gross'] ?? null);
        $fee = $this->parseAmount($mappedRow['fee'] ?? null);
        $net = $this->parseAmount($mappedRow['net'] ?? null) ?? (
            $gross !== null ? round($gross + ($fee ?? 0), 2) : null
        );

        $initiationDate = $this->parseDate($mappedRow['date'] ?? null, $mappedRow['time'] ?? null);

        $normalized = [
            'paypal_account_id' => $account->id,
            'transaction_id' => trim((string) ($mappedRow['transaction_id'] ?? '')) ?: null,
            'paypal_reference_id' => null,
            'paypal_reference_id_type' => null,
            'invoice_id' => $mappedRow['invoice_id'] ?? null,
            'custom_field' => $mappedRow['custom_field'] ?? null,
            'transaction_event_code' => null,
            'transaction_status' => $mappedRow['status'] ?? null,
            'transaction_initiation_date' => $initiationDate,
            'transaction_updated_date' => $initiationDate,
            'gross_amount' => $gross,
            'fee_amount' => $fee,
            'net_amount' => $net,
            'currency' => $mappedRow['currency'] ?? $account->default_currency,
            'payer_name' => $mappedRow['name'] ?? null,
            'payer_email' => $mappedRow['email'] ?? null,
            'payer_country_code' => null,
            'payment_method_type' => null,
            'instrument_type' => null,
            'protection_eligibility' => null,
            'subject' => $mappedRow['subject'] ?? null,
            'note' => $mappedRow['note'] ?? null,
            'item_info' => null,
            'raw_payload' => ['csv_row' => $rawRow],
        ];

        $normalized['raw_hash'] = hash('sha256', json_encode($rawRow, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $normalized['dedupe_key'] = $this->dedupeKey($account, $normalized);

        return $normalized;
    }

    private function parseAmount(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-+');
        $value = preg_replace('/[^\d.,]/', '', $value);

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Whichever separator appears last is the decimal separator.
            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            // Only a comma: decimal separator if exactly 2 digits follow, else thousands separator.
            $value = (strlen($value) - $lastComma - 1 === 2)
                ? str_replace(',', '.', $value)
                : str_replace(',', '', $value);
        }

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return $negative ? -(float) $value : (float) $value;
    }

    private function parseDate(?string $date, ?string $time): ?Carbon
    {
        if (blank($date)) {
            return null;
        }

        try {
            return Carbon::parse(trim($date . ' ' . ($time ?? '')));
        } catch (\Throwable) {
            return null;
        }
    }

    private function dedupeKey(PaypalAccount $account, array $n): string
    {
        $parts = [
            'csv',
            $account->id,
            $n['transaction_id'],
            optional($n['transaction_initiation_date'])->toIso8601String(),
            $n['gross_amount'],
            $n['raw_hash'],
        ];

        return hash('sha256', implode('|', array_map(fn ($v) => (string) $v, $parts)));
    }
}
