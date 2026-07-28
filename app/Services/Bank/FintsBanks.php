<?php

namespace App\Services\Bank;

use App\Models\FintsBank;

/**
 * Lookup helper for the FinTS setup bank picker: search by name/place/BLZ/BIC
 * and resolve a BLZ to its label + FinTS URL. Backed by the fints_banks table
 * (see fints:import-banks).
 */
class FintsBanks
{
    /**
     * @return array<string, string> BLZ => label, for a Filament searchable Select
     */
    public static function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        // Case-INSENSITIVE (Postgres LIKE is case-sensitive!) token search: every
        // whitespace-separated token must appear somewhere in name/place/BLZ/BIC,
        // so "sparkasse nordwest" or "sparkasse wismar" both find the bank.
        // LOWER + || work identically on PostgreSQL and SQLite.
        $tokens = array_filter(preg_split('/\s+/', mb_strtolower($query)));
        $first = $tokens[0] ?? '';

        // Relevance beats alphabet: there are ~340 banks containing "sparkasse",
        // so a plain A-Z sort buried the wanted one far beyond any limit. Rank
        // name-start matches first, then early-in-name, then the rest.
        return FintsBank::query()
            ->where(function ($w) use ($tokens) {
                foreach ($tokens as $token) {
                    $w->whereRaw(
                        "LOWER(name || ' ' || COALESCE(ort, '') || ' ' || blz || ' ' || COALESCE(bic, '')) LIKE ?",
                        ['%' . $token . '%'],
                    );
                }
            })
            ->orderByRaw(
                'CASE WHEN LOWER(name) LIKE ? THEN 0 WHEN LOWER(name) LIKE ? THEN 1 ELSE 2 END',
                [$first . '%', '%' . $first . '%'],
            )
            ->orderByRaw('LENGTH(name)')
            ->orderBy('name')
            ->limit(60)
            ->get()
            ->mapWithKeys(fn (FintsBank $b) => [$b->blz => self::label($b)])
            ->all();
    }

    public static function find(string $blz): ?FintsBank
    {
        return FintsBank::find($blz);
    }

    public static function labelFor(?string $blz): string
    {
        if (! $blz) {
            return '';
        }

        $bank = self::find($blz);

        return $bank ? self::label($bank) : $blz;
    }

    public static function count(): int
    {
        return FintsBank::query()->count();
    }

    private static function label(FintsBank $bank): string
    {
        return trim("{$bank->name}, {$bank->ort} ({$bank->blz})", ', ');
    }
}
