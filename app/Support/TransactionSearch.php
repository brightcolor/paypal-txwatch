<?php

namespace App\Support;

use App\Models\Transaction;

/**
 * Searching transactions - and saying WHY each result came back.
 *
 * THE PROBLEM: a result never said what it matched. Half the searched fields are
 * columns hidden by default - e-mail, invoice id, transaction id - and the
 * full-text filter also searches the subject, which is no column at all. So a row
 * could appear with nothing on screen containing the term, and the only way to find
 * out was to switch columns on one at a time.
 *
 * CHECKED AND NOT A PROBLEM: case. Filament already lowercases both the term and
 * the column on PostgreSQL (generate_search_column_expression), so "voß" and "Voß"
 * both return the same three rows - measured on the real data, where the people
 * involved are Diana Voß, Jan Voß, Denise Voß and Yvonne Vossler. A raw
 * `LIKE '%voß%'` really is case-sensitive there, which is what made this look like
 * a bug at first; Filament does not issue one.
 *
 * The fields below mirror what is actually searched. A second list would drift, and
 * the explanation would start claiming reasons the search never used - or stay
 * silent about the one it did.
 */
class TransactionSearch
{
    /**
     * The searched fields, with the label a person reads.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'payer_name' => 'Name',
        'payer_email' => 'E-Mail',
        'custom_field' => 'Bestellnummer',
        'subject' => 'Betreff',
        'invoice_id' => 'Invoice ID',
        'transaction_id' => 'Transaktions-ID',
    ];

    /**
     * WHY this row is in the result - field by field, with the value in full.
     *
     * The full value, not a highlighted fragment: "Voß" tells nobody which Voß, and
     * the whole point of asking is to recognise the person or the order.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function explain(Transaction $transaction, ?string $term): array
    {
        $term = trim((string) $term);

        if ($term === '') {
            return [];
        }

        $treffer = [];

        foreach (self::FIELDS as $field => $label) {
            $wert = (string) ($transaction->{$field} ?? '');

            if ($wert !== '' && mb_stripos($wert, $term) !== false) {
                $treffer[] = ['label' => $label, 'value' => $wert];
            }
        }

        /*
         * NO ADDRESS HERE, and deliberately not faked. PayPal returns no address for
         * these transactions at all - 0 of 400 sampled ones carry one - only a
         * delivery NAME in `shipping_info.name`, which in practice repeats the payer.
         * Explaining a field that nothing searches would be worse than leaving it
         * out: it could only ever appear next to another hit, never produce one, and
         * would suggest the search reaches further than it does.
         */

        return $treffer;
    }

    /**
     * The term currently being searched, from the box or from the full-text filter.
     *
     * Both are read because both can be active, and a result that appeared because
     * of the filter needs its reason just as much.
     */
    public static function term(mixed $livewire): ?string
    {
        $begriff = trim((string) ($livewire?->getTableSearch() ?? ''));

        if ($begriff !== '') {
            return $begriff;
        }

        $filter = $livewire?->tableFilters['custom_field_search'] ?? null;

        $ausFilter = trim((string) ($filter['value'] ?? ''));

        return $ausFilter !== '' ? $ausFilter : null;
    }
}
