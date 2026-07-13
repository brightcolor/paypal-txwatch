<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row GoCardless (PSD2) bank connection. Holds the encrypted API
 * credentials and the live consent state. The actual API talk lives in
 * App\Services\Bank\GoCardlessClient / GoCardlessSync.
 */
class BankConnection extends Model
{
    use \App\Models\Concerns\Auditable;

    // Secrets are deliberately NOT audited.
    protected static array $auditAttributes = ['provider', 'institution_id', 'institution_name', 'status'];

    protected static string $auditLogName = 'bank';

    protected static function auditLabel(): string
    {
        return 'Bankverbindung';
    }

    public const STATUS_NEW = 'new';
    public const STATUS_LINKING = 'linking';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'provider', 'secret_id', 'secret_key', 'institution_id', 'institution_name',
        'requisition_id', 'requisition_ref', 'agreement_id', 'account_ids',
        'status', 'consent_expires_at', 'last_synced_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'secret_id' => 'encrypted',
            'secret_key' => 'encrypted',
            'account_ids' => 'array',
            'consent_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['provider' => 'gocardless']);
    }

    public function hasCredentials(): bool
    {
        return filled($this->secret_id) && filled($this->secret_key);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED && filled($this->account_ids);
    }

    public function consentDaysLeft(): ?int
    {
        return $this->consent_expires_at ? (int) now()->diffInDays($this->consent_expires_at, false) : null;
    }
}
