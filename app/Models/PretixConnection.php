<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PretixConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'base_url',
        'organizer_slug',
        'api_token',
        'is_active',
        'sync_enabled',
        'bank_transfer_fee_cents',
        'import_paypal_orders',
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
            'bank_transfer_fee_cents' => 'integer',
            'last_synced_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
        ];
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
