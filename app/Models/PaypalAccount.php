<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaypalAccount extends Model
{
    use HasFactory;
    use \App\Models\Concerns\Auditable;

    /** Audited attributes (client_secret deliberately excluded - never log secrets). */
    protected static array $auditAttributes = ['name', 'mode', 'client_id', 'is_active', 'sync_enabled', 'sync_interval_minutes'];

    protected static string $auditLogName = 'paypal';

    protected static function auditLabel(): string
    {
        return 'PayPal-Konto';
    }

    protected $fillable = [
        'name',
        'mode',
        'client_id',
        'client_secret',
        'default_currency',
        'is_active',
        'sync_enabled',
        'sync_interval_minutes',
        'lookback_hours',
    ];

    protected $hidden = [
        'client_id',
        'client_secret',
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
            'is_active' => 'boolean',
            'sync_enabled' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function eventAssignmentRules(): HasMany
    {
        return $this->hasMany(EventAssignmentRule::class);
    }

    public function isLive(): bool
    {
        return $this->mode === 'live';
    }

    public function apiBaseUrl(): string
    {
        return config('paypal.endpoints.' . $this->mode);
    }

    public function effectiveLookbackHours(): int
    {
        return $this->lookback_hours ?? (int) config('paypal.default_lookback_hours');
    }

    public function hasValidCachedToken(): bool
    {
        return filled($this->access_token)
            && $this->access_token_expires_at
            && $this->access_token_expires_at->isFuture();
    }

    /**
     * True when this account is due for a sync (per its own interval) but
     * has had no *successful* sync for longer than the warning threshold -
     * surfaced as a "Sync-Warnung" on the Dashboard.
     */
    public function isSyncOverdue(): bool
    {
        if (! $this->sync_enabled) {
            return false;
        }

        $thresholdMinutes = max(
            (int) config('paypal.sync_warning_threshold_hours') * 60,
            $this->sync_interval_minutes * 4,
        );

        if (! $this->last_successful_sync_at) {
            // Never synced yet: only a warning once it's had time to run at least once.
            return $this->created_at->addMinutes($thresholdMinutes)->isPast();
        }

        return $this->last_successful_sync_at->addMinutes($thresholdMinutes)->isPast();
    }
}
