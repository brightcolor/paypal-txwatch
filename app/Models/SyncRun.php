<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    use HasFactory;

    public const TYPE_SCHEDULED = 'scheduled';
    public const TYPE_MANUAL = 'manual';
    public const TYPE_BACKFILL = 'backfill';
    public const TYPE_CSV_IMPORT = 'csv_import';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'paypal_account_id',
        'type',
        'status',
        'window_start',
        'window_end',
        'started_at',
        'finished_at',
        'duration_ms',
        'imported_count',
        'updated_count',
        'skipped_count',
        'error_count',
        'api_requests_count',
        'error_message',
        'triggered_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function paypalAccount(): BelongsTo
    {
        return $this->belongsTo(PaypalAccount::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function importErrors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }

    public function markFinished(string $status): void
    {
        $this->status = $status;
        $this->finished_at = now();
        $this->duration_ms = $this->started_at->diffInMilliseconds($this->finished_at);
        $this->save();
    }
}
