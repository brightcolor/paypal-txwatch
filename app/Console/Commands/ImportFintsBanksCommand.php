<?php

namespace App\Console\Commands;

use App\Models\FintsBank;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports the official FinTS institute list (semicolon-separated CSV, Windows-1252)
 * into the fints_banks table, keeping only institutes that offer a PIN/TAN URL.
 * Powers the bank picker in the FinTS setup.
 *
 * The CSV itself is NOT part of this (public) repo - the Deutsche Kreditwirtschaft
 * provides it to registered manufacturers only. Run on the server after copying
 * the CSV into the container:
 *   php artisan fints:import-banks "/tmp/fints_institute.csv"
 */
class ImportFintsBanksCommand extends Command
{
    protected $signature = 'fints:import-banks {file : Pfad zur offiziellen FinTS-Institutsliste (CSV, ;-getrennt)}';

    protected $description = 'FinTS-Institutsliste (BLZ + PIN/TAN-URL) für den Bank-Picker importieren.';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Datei nicht lesbar: {$file}");

            return self::FAILURE;
        }

        $fh = fopen($file, 'r');
        $header = $this->row($fh);
        if (! $header) {
            $this->error('Leere Datei / kein Header.');

            return self::FAILURE;
        }

        $col = $this->columns($header);
        foreach (['blz', 'name', 'url'] as $need) {
            if (! isset($col[$need])) {
                $this->error("Spalte für '{$need}' nicht gefunden. Header: " . implode(' | ', $header));
                fclose($fh);

                return self::FAILURE;
            }
        }

        $banks = [];
        $seen = 0;
        while (($r = $this->row($fh)) !== null) {
            $seen++;
            $blz = trim($r[$col['blz']] ?? '');
            $url = trim($r[$col['url']] ?? '');
            if ($blz === '' || stripos($url, 'http') !== 0) {
                continue;
            }
            if (isset($banks[$blz])) {
                continue; // keep first entry per BLZ
            }
            $banks[$blz] = [
                'blz' => $blz,
                'name' => trim($r[$col['name']] ?? ''),
                'ort' => isset($col['ort']) ? trim($r[$col['ort']] ?? '') : null,
                'bic' => isset($col['bic']) ? trim($r[$col['bic']] ?? '') : null,
                'url' => $url,
            ];
        }
        fclose($fh);

        DB::transaction(function () use ($banks) {
            FintsBank::query()->delete();
            foreach (array_chunk(array_values($banks), 500) as $chunk) {
                FintsBank::insert($chunk);
            }
        });

        $this->info(count($banks) . " Banken mit PIN/TAN-Zugang importiert (aus {$seen} Zeilen).");

        return self::SUCCESS;
    }

    /** Read one CSV row, converting Windows-1252 to UTF-8; null at EOF. */
    private function row($fh): ?array
    {
        $r = fgetcsv($fh, 0, ';');
        if ($r === false || $r === null) {
            return null;
        }

        return array_map(
            fn ($v) => $v === null ? '' : mb_convert_encoding($v, 'UTF-8', 'Windows-1252'),
            $r,
        );
    }

    /**
     * Map logical columns to their index by header name (robust to reordering).
     *
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function columns(array $header): array
    {
        $col = [];
        foreach ($header as $i => $h) {
            $h = mb_strtolower(trim($h));
            if ($h === 'blz') {
                $col['blz'] = $i;
            } elseif ($h === 'institut') {
                $col['name'] = $i;
            } elseif ($h === 'ort') {
                $col['ort'] = $i;
            } elseif ($h === 'bic') {
                $col['bic'] = $i;
            } elseif (str_contains($h, 'pin/tan') && str_contains($h, 'url')) {
                $col['url'] = $i;
            }
        }

        return $col;
    }
}
