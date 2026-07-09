<?php

namespace App\Services\Sync;

/**
 * Suggests a column mapping from a PayPal "Activity Download" CSV's header
 * row to our normalized fields, so the CSV-import wizard can pre-fill
 * sensible defaults instead of making the user map every column by hand.
 * Headers are matched case-insensitively against both the English and
 * German PayPal export column names.
 */
class CsvColumnGuesser
{
    /**
     * target field => candidate header names (checked in order, first match wins)
     */
    public const CANDIDATES = [
        'transaction_id' => ['transaction id', 'transaktionscode', 'transaktions-id'],
        'date' => ['date', 'datum'],
        'time' => ['time', 'zeit'],
        'gross' => ['gross', 'brutto'],
        'fee' => ['fee', 'gebühr', 'gebuehr'],
        'net' => ['net', 'netto'],
        'currency' => ['currency', 'währung', 'waehrung'],
        'name' => ['name'],
        'email' => ['from email address', 'e-mail-adresse des absenders', 'email'],
        'status' => ['status'],
        'custom_field' => ['custom number', 'benutzerdefinierte nummer', 'custom field'],
        'invoice_id' => ['invoice number', 'rechnungsnummer', 'invoice id'],
        'subject' => ['subject', 'betreff'],
        'note' => ['note', 'vermerk', 'notiz'],
    ];

    /**
     * @param  array<int, string>  $headers  raw header row from the uploaded file
     * @return array<string, ?string> target field => matched header (or null if no match found)
     */
    public static function guess(array $headers): array
    {
        $normalizedHeaders = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $headers);

        $mapping = [];

        foreach (self::CANDIDATES as $field => $candidates) {
            $mapping[$field] = null;

            foreach ($candidates as $candidate) {
                $index = array_search($candidate, $normalizedHeaders, true);

                if ($index !== false) {
                    $mapping[$field] = $headers[$index];

                    break;
                }
            }
        }

        return $mapping;
    }
}
