<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row FinTS/HBCI bank connection. Holds the encrypted login credentials
 * and the persisted (logged-in) FinTS session, so the daily sync can pull
 * statements directly from the bank without a third-party aggregator. The
 * actual protocol talk lives in App\Services\Bank\FintsClient / FintsSync.
 */
class FintsConnection extends Model
{
    use \App\Models\Concerns\Auditable;

    // Secrets and session blobs are deliberately NOT audited.
    protected static array $auditAttributes = ['bank_code', 'iban', 'status'];

    protected static string $auditLogName = 'bank';

    protected static function auditLabel(): string
    {
        return 'Bankverbindung (FinTS)';
    }

    public const STATUS_NEW = 'new';
    public const STATUS_NEEDS_TAN = 'needs_tan';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_NEEDS_REAUTH = 'needs_reauth';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'bank_code', 'fints_url', 'product_id', 'product_version',
        'username', 'pin', 'tan_mode', 'tan_medium', 'iban',
        'persisted_state', 'pending_state', 'pending_action', 'tan_challenge', 'tan_image',
        'status', 'last_synced_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'username' => 'encrypted',
            'pin' => 'encrypted',
            'persisted_state' => 'encrypted',
            'pending_state' => 'encrypted',
            'pending_action' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['product_version' => '1.0']);
    }

    /** Enough config to attempt a login. */
    public function hasCredentials(): bool
    {
        return filled($this->bank_code) && filled($this->fints_url)
            && filled($this->product_id) && filled($this->username) && filled($this->pin);
    }

    /** Logged in and ready for unattended statement pulls. */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && filled($this->persisted_state);
    }
}
