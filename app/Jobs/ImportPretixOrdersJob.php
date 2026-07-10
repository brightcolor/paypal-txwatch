<?php

namespace App\Jobs;

use App\Models\PretixConnection;
use App\Services\Pretix\PretixOrderImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs the pretix order import + reconciliation off the web request. The
 * synchronous version timed out (white page) once an organizer had many
 * orders; a queued job has no web/FPM timeout and processes event by event
 * (each order upserted as it is fetched, so partial progress persists).
 *
 * ShouldBeUnique keeps a second trigger for the same connection from piling up
 * while one run is still going.
 */
class ImportPretixOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $pretixConnectionId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->pretixConnectionId;
    }

    public function handle(PretixOrderImporter $importer): void
    {
        $connection = PretixConnection::find($this->pretixConnectionId);

        if (! $connection) {
            return;
        }

        $connection->forceFill(['import_running' => true])->save();

        try {
            $r = $importer->import($connection);

            $connection->forceFill([
                'import_running' => false,
                'last_import_summary' => "{$r['orders']} Bestellungen / {$r['events']} Event(s) · abgeglichen {$r['matched']}, Abweichung {$r['mismatch']}, nicht in pretix {$r['unmatched']}",
            ])->save();
        } catch (Throwable $e) {
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
