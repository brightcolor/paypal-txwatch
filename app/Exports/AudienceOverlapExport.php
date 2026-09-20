<?php

namespace App\Exports;

use App\Models\Event;
use App\Services\Audience\AudienceOverlap;
use App\Services\Audience\AudienceQuery;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The overlap matrix as a file: one row per event, one column per event.
 *
 * The counts travel, the percentages do not - a spreadsheet can divide, and two
 * numbers per cell would make the file unusable for exactly that.
 */
class AudienceOverlapExport implements FromArray, WithHeadings
{
    /** @var array{events: array<int, string>, rows: array<string, array{buyers: int, shared: array<string, array{count: int, share: float}>}>} */
    private array $matrix;

    /** @var array<string, string> */
    private array $namen;

    public function __construct(AudienceQuery $query)
    {
        $this->matrix = app(AudienceOverlap::class)->matrix($query);
        $this->namen = Event::namesBySlug();
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return array_merge(
            ['Veranstaltung', 'Käufer'],
            array_map(fn (string $slug) => $this->label($slug), $this->matrix['events']),
        );
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        $zeilen = [];

        foreach ($this->matrix['rows'] as $slug => $zeile) {
            $werte = [$this->label($slug), $zeile['buyers']];

            foreach ($this->matrix['events'] as $spalte) {
                $werte[] = $zeile['shared'][$spalte]['count'];
            }

            $zeilen[] = $werte;
        }

        return $zeilen;
    }

    private function label(string $slug): string
    {
        return $this->namen[$slug] ?? $slug;
    }
}
