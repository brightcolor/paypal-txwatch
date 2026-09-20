<?php

namespace App\Exports;

use App\Services\Audience\AudienceBuyers;
use App\Services\Audience\AudienceQuery;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The buyer list as a file.
 *
 * The decisions - who counts as a buyer, what the totals are - belong to
 * AudienceBuyers; this only lays them out. Built as an array because the event
 * names need a lookup per buyer, and at a few thousand people the list fits in
 * memory comfortably.
 */
class AudienceBuyersExport implements FromArray, WithHeadings
{
    public function __construct(private readonly AudienceQuery $query)
    {
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['E-Mail', 'Name', 'Veranstaltungen', 'Bestellungen', 'Tickets', 'Umsatz', 'Erste Bestellung', 'Letzte Bestellung'];
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        $dienst = app(AudienceBuyers::class);
        $zeilen = [];

        foreach ($dienst->query($this->query)->orderByDesc('tickets')->orderBy('buyer_email')->get() as $kaeufer) {
            $zeilen[] = [
                (string) $kaeufer->buyer_email,
                (string) ($kaeufer->buyer_display_name ?? ''),
                implode(', ', $dienst->eventNames($this->query, (string) $kaeufer->buyer_email)),
                (int) $kaeufer->orders,
                (int) $kaeufer->tickets,
                round((float) $kaeufer->revenue, 2),
                $kaeufer->first_at ? Carbon::parse($kaeufer->first_at)->format('d.m.Y') : '',
                $kaeufer->last_at ? Carbon::parse($kaeufer->last_at)->format('d.m.Y') : '',
            ];
        }

        return $zeilen;
    }
}
