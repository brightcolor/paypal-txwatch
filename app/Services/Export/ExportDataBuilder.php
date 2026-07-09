<?php

namespace App\Services\Export;

use App\Models\ExportTemplate;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns a (already filtered/sorted) Transaction query + an export
 * configuration into a plain-array structure ready to be rendered as
 * PDF/CSV/XLSX: resolved columns, optional grouping with per-group sums,
 * and a grand total. Deliberately framework-view-agnostic and Browsershot-
 * free so the export logic itself (grouping, sums, PII masking, column
 * selection) can be unit tested without a headless Chromium.
 */
class ExportDataBuilder
{
    /**
     * @param  array<string,mixed>  $overrides  merged on top of the template (or used standalone if no template)
     */
    public function build(Builder $query, ?ExportTemplate $template, array $overrides = []): array
    {
        $config = $this->resolveConfig($template, $overrides);
        $transactions = $query->get();

        $columns = $this->visibleColumns($config['columns'], $config['mode']);

        $groups = $config['group_by']
            ? $this->groupTransactions($transactions, $config['group_by'])
            : collect(['' => $transactions]);

        $renderedGroups = $groups->map(function (Collection $rows, string $label) use ($columns, $config) {
            return [
                'label' => $label,
                'rows' => $rows->map(fn (Transaction $t) => $this->renderRow($t, $columns, $config['mask_pii']))->all(),
                'sum' => $config['show_group_sums'] ? $this->sum($rows) : null,
            ];
        })->values()->all();

        $sharedEvent = $transactions->pluck('event_id')->unique()->filter();
        $event = ($config['show_event_info'] && $sharedEvent->count() === 1)
            ? $transactions->first(fn (Transaction $t) => $t->event_id !== null)?->event
            : null;

        return [
            'title' => $config['title'] ?: 'PayPal-Transaktionsauswertung',
            'subtitle' => $config['subtitle'],
            'description' => $config['description'],
            'mode' => $config['mode'],
            'mask_pii' => $config['mask_pii'],
            'footer_note' => $config['footer_note'],
            'columns' => $columns,
            'column_labels' => array_map([ExportColumns::class, 'label'], $columns),
            'event' => $event,
            'period' => $this->period($transactions),
            'generated_at' => now(),
            'groups' => $renderedGroups,
            'grand_total' => $config['show_grand_total'] ? $this->sum($transactions) : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveConfig(?ExportTemplate $template, array $overrides): array
    {
        $defaults = [
            'columns' => ExportTemplate::DEFAULT_COLUMNS,
            'group_by' => null,
            'show_group_sums' => true,
            'show_grand_total' => true,
            'mode' => ExportTemplate::MODE_CUSTOMER,
            'mask_pii' => false,
            'title' => null,
            'subtitle' => null,
            'description' => null,
            'show_event_info' => true,
            'footer_note' => 'Diese Auswertung basiert auf den zum Exportzeitpunkt lokal synchronisierten PayPal-Transaktionsdaten.',
        ];

        $fromTemplate = $template
            ? \Illuminate\Support\Arr::only($template->toArray(), array_keys($defaults))
            : [];

        return array_merge($defaults, $fromTemplate, $overrides);
    }

    private function visibleColumns(array $columns, string $mode): array
    {
        if ($mode !== ExportTemplate::MODE_CUSTOMER) {
            return $columns;
        }

        return array_values(array_diff($columns, ExportColumns::INTERNAL_ONLY));
    }

    private function renderRow(Transaction $t, array $columns, bool $maskPii): array
    {
        $row = [];

        foreach ($columns as $column) {
            $row[$column] = ExportColumns::value($t, $column, $maskPii);
        }

        return $row;
    }

    /**
     * @return Collection<string, Collection<int, Transaction>>
     */
    private function groupTransactions(Collection $transactions, string $groupBy): Collection
    {
        return $transactions->groupBy(function (Transaction $t) use ($groupBy) {
            return match ($groupBy) {
                'event' => $t->event?->displayName() ?? 'Ohne Event',
                'day' => optional($t->transaction_initiation_date)->format('d.m.Y') ?? '–',
                'week' => $t->transaction_initiation_date
                    ? 'KW ' . $t->transaction_initiation_date->isoWeek . '/' . $t->transaction_initiation_date->isoWeekYear
                    : '–',
                'month' => optional($t->transaction_initiation_date)->translatedFormat('F Y') ?? '–',
                'status' => $t->transaction_status ?? '–',
                'currency' => $t->currency ?? '–',
                default => '',
            };
        })->sortKeys();
    }

    private function sum(Collection $transactions): array
    {
        return [
            'count' => $transactions->count(),
            'gross' => $transactions->sum(fn (Transaction $t) => (float) $t->gross_amount),
            'fee' => $transactions->sum(fn (Transaction $t) => (float) $t->fee_amount),
            'net' => $transactions->sum(fn (Transaction $t) => (float) $t->net_amount),
        ];
    }

    private function period(Collection $transactions): array
    {
        $dates = $transactions->pluck('transaction_initiation_date')->filter();

        return [
            'from' => $dates->min(),
            'to' => $dates->max(),
        ];
    }
}
