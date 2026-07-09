<?php

namespace App\Services\Sync;

use App\Models\PaypalAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Turns a single raw record from PayPal's /v1/reporting/transactions
 * "transaction_details" array into the flat, normalized shape stored on
 * the transactions table, plus the fields needed for idempotent upsert
 * (raw_hash, dedupe_key).
 */
class TransactionNormalizer
{
    /**
     * @return array<string, mixed> normalized attributes, matching Transaction::$fillable
     *                               (minus assignment/event fields, which SyncService fills in)
     */
    public function normalize(PaypalAccount $account, array $raw): array
    {
        $info = $raw['transaction_info'] ?? [];
        $payer = $raw['payer_info'] ?? [];
        $payerName = $payer['payer_name'] ?? [];
        $cart = $raw['cart_info'] ?? [];

        $gross = $this->amount($info['transaction_amount'] ?? null);
        $fee = $this->amount($info['fee_amount'] ?? null);
        $net = isset($info['transaction_net_amount'])
            ? $this->amount($info['transaction_net_amount'])
            : ($gross !== null ? round($gross + ($fee ?? 0), 2) : null);

        $normalized = [
            'paypal_account_id' => $account->id,
            'transaction_id' => $info['transaction_id'] ?? null,
            'paypal_reference_id' => $info['paypal_reference_id'] ?? null,
            'paypal_reference_id_type' => $info['paypal_reference_id_type'] ?? null,
            'invoice_id' => $info['invoice_id'] ?? null,
            'custom_field' => $info['custom_field'] ?? null,
            'transaction_event_code' => $info['transaction_event_code'] ?? null,
            'transaction_status' => $info['transaction_status'] ?? null,
            'transaction_initiation_date' => $this->parseDate($info['transaction_initiation_date'] ?? null),
            'transaction_updated_date' => $this->parseDate($info['transaction_updated_date'] ?? null),
            'gross_amount' => $gross,
            'fee_amount' => $fee,
            'net_amount' => $net,
            'currency' => $info['transaction_amount']['currency_code'] ?? $account->default_currency,
            'payer_name' => $this->fullName($payerName),
            'payer_email' => $payer['email_address'] ?? null,
            'payer_country_code' => $payer['country_code'] ?? null,
            'payment_method_type' => $info['payment_method_type'] ?? null,
            'instrument_type' => $info['instrument_type'] ?? null,
            'protection_eligibility' => $info['protection_eligibility'] ?? null,
            'subject' => $info['transaction_subject'] ?? null,
            'note' => $info['transaction_note'] ?? null,
            'item_info' => $cart['item_details'] ?? null,
            'raw_payload' => $raw,
        ];

        $normalized['raw_hash'] = hash('sha256', json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $normalized['dedupe_key'] = $this->dedupeKey($account, $normalized);

        return $normalized;
    }

    private function amount(?array $amount): ?float
    {
        if ($amount === null || ! isset($amount['value'])) {
            return null;
        }

        return (float) $amount['value'];
    }

    private function fullName(array $payerName): ?string
    {
        $name = trim(($payerName['given_name'] ?? '') . ' ' . ($payerName['surname'] ?? ''));

        return $name !== '' ? $name : ($payerName['alternate_full_name'] ?? null);
    }

    private function parseDate(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }

    private function dedupeKey(PaypalAccount $account, array $n): string
    {
        $parts = [
            $account->id,
            $n['transaction_id'],
            $n['transaction_event_code'],
            optional($n['transaction_initiation_date'])->toIso8601String(),
            optional($n['transaction_updated_date'])->toIso8601String(),
            $n['paypal_reference_id'],
            $n['gross_amount'],
            $n['raw_hash'],
        ];

        return hash('sha256', implode('|', Arr::map($parts, fn ($v) => (string) $v)));
    }
}
