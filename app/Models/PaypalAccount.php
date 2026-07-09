<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaypalAccount extends Model
{
    use HasFactory;

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
}
