<?php

namespace App\Jobs;

use App\Models\PretixConnection;
use App\Models\PretixImportRun;
use App\Services\Pretix\PretixOrderImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the pretix order import + reconciliation off the web request. The
 * synchronous version timed out (white page) once an organizer had many
 * orders; a queued job has no web/FPM timeout and processes event by event
 * (each order upserted as it is fetched, so partial progress persists).
 *
 * Deliberately NOT ShouldBeUnique: its invisible cache lock silently dropped
 * re-dispatches after a killed run in production (twice), with no queue entry,
 * no failed job and no log line to show why. Concurrency is instead guarded
 * explicitly in handle() via the PretixImportRun table (skip when another run
 * for this connection is 'running' and younger than the job timeout), which is
 * observable and self-healing.
 */
class ImportPretixOrdersJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $pretixConnectionId)
    {
    }

    public function handle(PretixOrderImporter $importer): void
    {
        $connection = PretixConnection::find($this->pretixConnectionId);

        if (! $connection) {
            return;
        }

        $activeRun = PretixImportRun::query()
            ->where('pretix_connection_id', $connection->id)
            ->where('status', PretixImportRun::STATUS_RUNNING)
            ->where('started_at', '>', now()->subSeconds($this->timeout))
            ->exists();

        if ($activeRun) {
            Log::info("pretix-Import für Verbindung {$connection->id} übersprungen: es läuft bereits ein Import.");

            return;
        }

        $connection->forceFill(['import_running' => true])->save();

        $run = PretixImportRun::create([
            'pretix_connection_id' => $connection->id,
            'status' => PretixImportRun::STATUS_RUNNING,
            'started_at' => now(),
            'log' => [],
        ]);

        try {
            $r = $importer->import(
                $connection,
                fn (string $message, array $patch = []) => $run->pushLog($message, $patch),
            );

            $run->forceFill([
                'status' => PretixImportRun::STATUS_SUCCESS,
                'finished_at' => now(),
            ])->save();

            $connection->forceFill([
                'import_running' => false,
                'last_import_summary' => "{$r['orders']} Bestellungen / {$r['events']} Event(s) · abgeglichen {$r['matched']}, Abweichung {$r['mismatch']}, nicht in pretix {$r['unmatched']}",
            ])->save();
        } catch (Throwable $e) {
            $run->pushLog('Fehler: ' . $e->getMessage());
            $run->forceFill([
                'status' => PretixImportRun::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            $connection->forceFill(['import_running' => false])->save();

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $connection = PretixConnection::find($this->pretixConnectionId);

        $connection?->forceFill([
            'import_running' => false,
            'last_error' => $e->getMessage(),
        ])->save();
    }
}
