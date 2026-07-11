<?php

namespace App\Console\Commands;

use App\Models\ErrorLogEntry;
use Illuminate\Console\Command;

/**
 * Prints recently captured server errors for quick inspection over SSH:
 *   php artisan errors:recent            (last 20 unresolved, grouped)
 *   php artisan errors:recent --all      (include resolved)
 *   php artisan errors:recent --trace 42 (full trace + context for entry #42)
 */
class ErrorsRecentCommand extends Command
{
    protected $signature = 'errors:recent {--all : Include resolved entries} {--limit=20} {--trace= : Show full trace/context for a single entry id}';

    protected $description = 'List captured 5xx errors (from error_log_entries), most recent first.';

    public function handle(): int
    {
        if ($id = $this->option('trace')) {
            return $this->showOne((int) $id);
        }

        $query = ErrorLogEntry::query()->latest('last_seen_at');

        if (! $this->option('all')) {
            $query->where('resolved', false);
        }

        $entries = $query->limit((int) $this->option('limit'))->get();

        if ($entries->isEmpty()) {
            $this->info('Keine Fehler protokolliert. 🎉');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'zuletzt', 'x', 'Klasse', 'Ort', 'Route/URL', 'Nachricht'],
            $entries->map(fn (ErrorLogEntry $e) => [
                $e->id,
                $e->last_seen_at?->format('d.m H:i'),
                $e->occurrences,
                $e->shortClass(),
                $e->shortLocation(),
                $e->route ?: \Illuminate\Support\Str::limit($e->url, 30),
                \Illuminate\Support\Str::limit($e->message, 60),
            ])->all()
        );

        $this->line('Details: php artisan errors:recent --trace <#>');

        return self::SUCCESS;
    }

    private function showOne(int $id): int
    {
        $e = ErrorLogEntry::find($id);

        if (! $e) {
            $this->error("Kein Eintrag #{$id}.");

            return self::FAILURE;
        }

        $this->line("<info>#{$e->id}</info> {$e->exception_class}");
        $this->line("Nachricht: {$e->message}");
        $this->line("Ort:       {$e->file}:{$e->line}");
        $this->line("Status:    {$e->status_code}   Vorkommen: {$e->occurrences}");
        $this->line("Request:   {$e->method} {$e->url}");
        $this->line("Route:     " . ($e->route ?: '–') . "   User: " . ($e->user_id ?: '–'));
        $this->line("Version:   {$e->app_version}");
        $this->line("Zeitraum:  {$e->first_seen_at} → {$e->last_seen_at}");
        $this->newLine();
        $this->line('<comment>Kontext:</comment>');
        $this->line(json_encode($e->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->newLine();
        $this->line('<comment>Trace:</comment>');
        $this->line($e->trace);

        return self::SUCCESS;
    }
}
