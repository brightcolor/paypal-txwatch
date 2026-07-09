<?php

namespace App\Services\Export;

use App\Models\Transaction;

/**
 * Single source of truth for exportable columns: label (shown when picking
 * columns for a template/export) and how to pull the value out of a
 * Transaction for CSV/XLSX/PDF rendering. Internal-only columns are
 * excluded from "customer" mode exports (see PdfExportService).
 */
class ExportColumns
{
    public const LABELS = [
        'date' => 'Datum',
        'transaction_id' => 'Transaktions-ID',
        'name' => 'Name',
        'email' => 'E-Mail',
        'custom_field' => 'Custom Field',
        'invoice_id' => 'Invoice ID',
        'status' => 'Status',
        'gross' => 'Brutto',
        'fee' => 'Gebühr',
        'net' => 'Netto',
        'currency' => 'Währung',
        'event' => 'Event',
        'payment_method' => 'Zahlungsart',
        'country' => 'Land',
        'reference_id' => 'Reference ID',
        't_code' => 'T-Code',
        'paypal_account' => 'PayPal-Konto',
        'internal_id' => 'Interne ID',
    ];

    public const INTERNAL_ONLY = ['internal_id', 'paypal_account', 't_code', 'reference_id'];

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    /**
     * @return array<string, mixed> column key => rendered value
     */
    public static function value(Transaction $transaction, string $key, bool $maskPii = false): mixed
    {
        return match ($key) {
            'date' => optional($transaction->transaction_initiation_date)->format('d.m.Y H:i'),
            'transaction_id' => $transaction->transaction_id,
            'name' => $maskPii ? self::mask($transaction->payer_name) : $transaction->payer_name,
            'email' => $maskPii ? self::mask($transaction->payer_email) : $transaction->payer_email,
            'custom_field' => $transaction->custom_field,
            'invoice_id' => $transaction->invoice_id,
            'status' => $transaction->transaction_status,
            'gross' => $transaction->gross_amount,
            'fee' => $transaction->fee_amount,
            'net' => $transaction->net_amount,
            'currency' => $transaction->currency,
            'event' => $transaction->event?->displayName() ?? '–',
            'payment_method' => $transaction->payment_method_type,
            'country' => $transaction->payer_country_code,
            'reference_id' => $transaction->paypal_reference_id,
            't_code' => $transaction->transaction_event_code,
            'paypal_account' => $transaction->paypalAccount?->name,
            'internal_id' => $transaction->id,
            default => null,
        };
    }

    public static function isNumeric(string $key): bool
    {
        return in_array($key, ['gross', 'fee', 'net'], true);
    }

    private static function mask(?string $value): ?string
    {
        if (blank($value)) {
            return $value;
        }

        return mb_substr($value, 0, 2) . str_repeat('*', max(1, mb_strlen($value) - 2));
    }
}
