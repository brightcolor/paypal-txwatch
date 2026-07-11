<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Resolves the dashboard's Matomo-style period picker (preset + optional
 * custom from/until) into a concrete [from, until, label] tuple that the
 * KPI tiles and the revenue chart share. Presets are calendar-based
 * (this month = 1st..today etc.); 'all' means unbounded.
 */
class DashboardRange
{
    public const DEFAULT = '30d';

    public const PRESETS = [
        'today' => 'Heute',
        'yesterday' => 'Gestern',
        '7d' => 'Letzte 7 Tage',
        '30d' => 'Letzte 30 Tage',
        '90d' => 'Letzte 90 Tage',
        'month' => 'Dieser Monat',
        'last_month' => 'Letzter Monat',
        'year' => 'Dieses Jahr',
        'last_year' => 'Letztes Jahr',
        'all' => 'Gesamter Zeitraum',
        'custom' => 'Benutzerdefiniert…',
    ];

    /**
     * @param  array<string, mixed>|null  $filters  the dashboard filter state
     * @return array{0: ?Carbon, 1: ?Carbon, 2: string} [from, until, label]
     */
    public static function resolve(?array $filters): array
    {
        $preset = $filters['range'] ?? self::DEFAULT;
        $now = Carbon::now();

        return match ($preset) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), self::PRESETS['today']],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay(), self::PRESETS['yesterday']],
            '7d' => [$now->copy()->subDays(7)->startOfDay(), $now->copy()->endOfDay(), self::PRESETS['7d']],
            '90d' => [$now->copy()->subDays(90)->startOfDay(), $now->copy()->endOfDay(), self::PRESETS['90d']],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay(), self::PRESETS['month']],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
                self::PRESETS['last_month'],
            ],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay(), self::PRESETS['year']],
            'last_year' => [
                $now->copy()->subYear()->startOfYear(),
                $now->copy()->subYear()->endOfYear(),
                self::PRESETS['last_year'],
            ],
            'all' => [null, null, self::PRESETS['all']],
            'custom' => self::custom($filters, $now),
            default => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay(), self::PRESETS['30d']],
        };
    }

    /** @param array<string, mixed>|null $filters */
    private static function custom(?array $filters, Carbon $now): array
    {
        $from = filled($filters['from'] ?? null) ? Carbon::parse($filters['from'])->startOfDay() : null;
        $until = filled($filters['until'] ?? null) ? Carbon::parse($filters['until'])->endOfDay() : $now->copy()->endOfDay();

        // Custom without a start date behaves like the default window instead
        // of silently querying everything.
        if (! $from) {
            return [$now->copy()->subDays(30)->startOfDay(), $until, self::PRESETS['30d']];
        }

        return [$from, $until, $from->format('d.m.Y') . ' – ' . $until->format('d.m.Y')];
    }

    /** Stable cache-key fragment for the resolved range (day precision). */
    public static function cacheKey(?array $filters): string
    {
        [$from, $until] = self::resolve($filters);

        return ($from?->format('Ymd') ?? 'open') . '-' . ($until?->format('Ymd') ?? 'open');
    }
}
