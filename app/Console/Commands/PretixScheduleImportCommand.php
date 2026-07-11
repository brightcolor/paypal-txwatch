<?php

namespace App\Console\Commands;

use App\Jobs\ImportPretixOrdersJob;
use App\Models\PretixConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every 30 minutes via the scheduler (routes/console.php) and dispatches
 * the pretix order import for every active connection with sync_enabled -
 * this is what makes the connection's "Automatischer Import aktiv" toggle
 * actually do something. Overlap with a still-running import is handled by
 * the job's own guard (skips + logs while a run younger than the job timeout
 * is in progress).
 */
#[Signature('pretix:schedule-import')]
#[Description('Dispatch the pretix order import for every active connection with sync enabled.')]
class PretixScheduleImportCommand extends Command
{
    public function handle(): int
    {
        $connections = PretixConnection::query()
            ->where('is_active', true)
            ->where('sync_enabled', true)
            ->get();

        foreach ($connections as $connection) {
            // Unlike the UI action we deliberately do NOT pre-set import_running
            // here: if the job's guard skips (import already in flight), a
            // pre-set flag would stick to "läuft…" forever. The job sets and
            // clears the flag itself.
            ImportPretixOrdersJob::dispatch($connection->id);

            $this->components->info("pretix-Import eingereiht für [{$connection->name}].");
        }

        if ($connections->isEmpty()) {
            $this->components->info('Keine Verbindung mit aktiviertem Auto-Import.');
        }

        return self::SUCCESS;
    }
}
