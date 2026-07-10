<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PretixImportRun extends Model
{
    use HasFactory;

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'pretix_connection_id',
        'status',
        'events_total',
        'events_done',
        'orders_imported',
        'matched',
        'mismatch',
        'unmatched',
        'log',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'log' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PretixConnection::class, 'pretix_connection_id');
    }

    /**
     * Appends a timestamped progress line and optionally patches counters,
     * then persists immediately so a live (polling) view can show it.
     *
     * @param  array<string, int|string>  $patch
     */
    public function pushLog(string $message, array $patch = []): void
    {
        $log = $this->log ?? [];
        $log[] = ['t' => now()->format('H:i:s'), 'm' => $message];

        $this->forceFill(array_merge($patch, ['log' => $log]))->save();
    }
}
