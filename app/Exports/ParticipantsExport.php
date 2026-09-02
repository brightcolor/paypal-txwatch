<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * CSV/XLSX wrapper around what ParticipantExporter built.
 *
 * Deliberately dumb: the decisions - which positions count, how addresses are
 * de-duplicated - belong to the service, so both formats show the same list.
 */
class ParticipantsExport implements FromArray, WithHeadings
{
    public function __construct(private readonly array $data)
    {
    }

    public function headings(): array
    {
        return $this->data['headings'];
    }

    public function array(): array
    {
        return $this->data['rows'];
    }
}
