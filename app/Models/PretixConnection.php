<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PretixConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'base_url',
        'organizer_slug',
        'api_token',
        'webhook_secret',
        'is_active',
        'sync_enabled',
        'bank_transfer_fee_cents',
        'import_paypal_orders',
        'last_import_summary',
        'import_running',
    ];

    protected $hidden = [
        'api_token',
    ];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'is_active' => 'boolean',
            'sync_enabled' => 'boolean',
            'import_paypal_orders' => 'boolean',
            'import_running' => 'boolean',
            'bank_transfer_fee_cents' => 'integer',
            'last_synced_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $connection) {
            $connection->webhook_secret ??= \Illuminate\Support\Str::random(48);
        });
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(PretixImportRun::class);
    }

    public function webhookUrl(): string
    {
        return url("/webhooks/pretix/{$this->webhook_secret}");
    }

    /**
     * Normalized API root, e.g. "https://pretix.eu/api/v1".
     */
    public function apiBaseUrl(): string
    {
        return rtrim($this->base_url, '/') . '/api/v1';
    }

    /**
     * Bank-transfer fee as a positive euro amount (e.g. 0.20).
     */
    public function bankTransferFee(): float
    {
        return round($this->bank_transfer_fee_cents / 100, 2);
    }
}
