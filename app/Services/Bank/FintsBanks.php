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

        return FintsBank::query()
            ->where(function ($w) use ($query) {
                $w->where('name', 'like', "%{$query}%")
                    ->orWhere('ort', 'like', "%{$query}%")
                    ->orWhere('blz', 'like', "{$query}%")
                    ->orWhere('bic', 'like', "{$query}%");
            })
            ->orderBy('name')
            ->limit(50)
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
