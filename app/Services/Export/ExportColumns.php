<?php

namespace App\Services\Export;

use App\Models\Transaction;
use App\Services\CustomFieldParser;

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
        'event_ref' => 'Event',
        'custom_field' => 'Bestellnummer',
        'invoice_id' => 'Invoice ID',
        'status' => 'Status',
        // "Brutto"/"Netto (o. MwSt)" are the tax-facing pair (VAT breakdown);
        // the after-fee amount deliberately avoids "Netto" to not conflate
        // payment fees with tax terminology.
        'gross' => 'Brutto',
        'net_excl_vat' => 'Netto (o. MwSt)',
        'vat' => 'MwSt',
        'fee' => 'Gebühr',
        'net' => 'Nach Gebühren',
        'currency' => 'Währung',
        'event' => 'Event (zugeordnet)',
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
    public static function value(Transaction $transaction, string $key, bool $maskPii = false, float $vatRate = 19.0): mixed
    {
        return match ($key) {
            'date' => optional($transaction->transaction_initiation_date)->format('d.m.Y H:i'),
            'transaction_id' => $transaction->transaction_id,
            'name' => $maskPii ? self::mask($transaction->payer_name) : $transaction->payer_name,
            'email' => $maskPii ? self::mask($transaction->payer_email) : $transaction->payer_email,
            // custom_field holds pretix' "Order <event>-<nr>"; the export shows the two
            // parts separately: "Bestellnummer" = the order number, "Event" = the event ref.
            'custom_field' => CustomFieldParser::orderNumber($transaction->custom_field),
            'event_ref' => CustomFieldParser::eventReference($transaction->custom_field),
            'invoice_id' => $transaction->invoice_id,
            'status' => $transaction->transaction_status,
            'gross' => $transaction->gross_amount,
            // Real pretix tax when the order is linked, flat-rate fallback otherwise.
            'vat' => $transaction->vatAmount($vatRate),
            'net_excl_vat' => round((float) $transaction->gross_amount - $transaction->vatAmount($vatRate), 2),
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

    /**
     * VAT contained in a gross (VAT-inclusive) amount, e.g. at 19% a gross of
     * 119.00 contains 19.00 VAT. This is the German B2C case: gross_amount is
     * what the customer actually paid, VAT included.
     */
    public static function vatAmount(float $gross, float $vatRate): float
    {
        if ($vatRate <= 0) {
            return 0.0;
        }

        return round($gross * $vatRate / (100 + $vatRate), 2);
    }

    /**
     * Human-readable rate for headings/labels: 19.00 -> "19", 7.50 -> "7,5".
     */
    public static function formatRate(float $vatRate): string
    {
        return rtrim(rtrim(number_format($vatRate, 2, ',', ''), '0'), ',');
    }

    public static function isNumeric(string $key): bool
    {
        return in_array($key, ['gross', 'vat', 'net_excl_vat', 'fee', 'net'], true);
    }

    /**
     * CSS class for the money colour scheme in the PDF export, matching the
     * app: Betrag/Brutto = accent, Nach Gebühren = green, charged fees and any
     * negative value = red. Returns null for neutral cells (VAT, positive fee).
     */
    public static function moneyClass(string $key, float $value): ?string
    {
        if (! self::isNumeric($key)) {
            return null;
        }

        if ($value < 0) {
            return 'money-neg';
        }

        return match ($key) {
            'gross' => 'money-amt',
            'net', 'net_excl_vat' => 'money-net',
            default => null,
        };
    }

    private static function mask(?string $value): ?string
    {
        if (blank($value)) {
            return $value;
        }

        return mb_substr($value, 0, 2) . str_repeat('*', max(1, mb_strlen($value) - 2));
    }
}
