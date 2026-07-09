<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportError extends Model
{
    use HasFactory;

    public const TYPE_API_ERROR = 'api_error';
    public const TYPE_VALIDATION = 'validation';
    public const TYPE_RESULTSET_TOO_LARGE = 'resultset_too_large';
    public const TYPE_RATE_LIMIT = 'rate_limit';
    public const TYPE_AUTH = 'auth';
    public const TYPE_UNKNOWN = 'unknown';

    protected $fillable = [
        'sync_run_id',
        'paypal_account_id',
        'transaction_id',
        'window_start',
        'window_end',
        'error_type',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'context' => 'array',
        ];
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }

    public function paypalAccount(): BelongsTo
    {
        return $this->belongsTo(PaypalAccount::class);
    }
}
