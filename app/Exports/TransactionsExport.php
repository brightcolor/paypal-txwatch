<?php

namespace App\Exports;

use App\Services\Export\ExportColumns;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * CSV/XLSX counterpart to the PDF export: consumes the same structured
 * array produced by ExportDataBuilder so both formats always reflect
 * identical filtering, column selection, grouping, and PII masking.
 */
class TransactionsExport implements FromArray, WithHeadings
{
    public function __construct(private readonly array $data)
    {
    }

    public function headings(): array
    {
        return array_values($this->data['column_labels']);
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->data['groups'] as $group) {
            if ($group['label'] !== '') {
                $rows[] = [$group['label']];
            }

            foreach ($group['rows'] as $row) {
                $rows[] = array_map(
                    fn ($key, $value) => ExportColumns::isNumeric($key) ? (float) $value : $value,
                    array_keys($row),
                    array_values($row),
                );
            }

            if ($group['sum']) {
                $rows[] = $this->sumRow('Summe (' . $group['sum']['count'] . ')', $group['sum']);
            }
        }

        if ($this->data['grand_total']) {
            $rows[] = [];
            $rows[] = $this->sumRow('Gesamtsumme (' . $this->data['grand_total']['count'] . ')', $this->data['grand_total']);
        }

        return $rows;
    }

    private function sumRow(string $label, array $sum): array
    {
        $row = array_fill(0, count($this->data['columns']), '');
        $row[0] = $label;

        foreach (['gross', 'fee', 'net'] as $field) {
            $index = array_search($field, $this->data['columns'], true);
            if ($index !== false) {
                $row[$index] = $sum[$field];
            }
        }

        return $row;
    }
}
