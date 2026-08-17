<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row Enable Banking (PSD2) connection. Holds the read-only session the
 * customer granted at their own bank, the accounts it covers and the date the
 * consent lapses. The protocol talk lives in App\Services\EnableBanking.
 *
 * NO CREDENTIALS IN HERE, and that is the whole point of this path: unlike
 * FintsConnection there is no PIN and no TAN, because the customer authorises on
 * the bank's own website and TxWatch only ever receives a token.
 */
class EnableBankingConnection extends Model
{
    use \App\Models\Concerns\Auditable;

    // The session id and the account handles are deliberately NOT audited.
    protected static array $auditAttributes = ['aspsp_name', 'aspsp_country', 'iban', 'status', 'access_valid_until'];

    protected static string $auditLogName = 'bank';

    protected static function auditLabel(): string
    {
        return 'Bankverbindung (Enable Banking)';
    }

    public const STATUS_NEW = 'new';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_ERROR = 'error';

    /**
     * How long before the consent lapses the status page starts warning.
     *
     * Two weeks, because re-authorising needs the account holder in front of
     * their bank's login - that is not something to discover on the morning it
     * already stopped working.
     */
    public const WARN_DAYS = 14;

    protected $fillable = [
        'aspsp_name', 'aspsp_country', 'session_id', 'accounts', 'iban',
        'access_valid_until', 'pending_state', 'pending_state_expires_at',
        'status', 'last_synced_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'session_id' => 'encrypted',
            'accounts' => 'encrypted:json',
            'access_valid_until' => 'datetime',
            'pending_state_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /** Connected and usable for an unattended pull. */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && filled($this->session_id)
            && ! $this->consentExpired();
    }

    /**
     * Has the bank's consent lapsed?
     *
     * A MISSING DATE IS NOT TREATED AS EXPIRED. Not every bank reports
     * `valid_until`, and refusing to pull from those would break the path for
     * them entirely; the API's own refusal is the authority in that case.
     */
    public function consentExpired(): bool
    {
        return $this->access_valid_until !== null && $this->access_valid_until->isPast();
    }

    /** Days left on the consent, or null when the bank named no deadline. */
    public function consentDaysLeft(): ?int
    {
        if ($this->access_valid_until === null) {
            return null;
        }

        return max(0, (int) now()->diffInDays($this->access_valid_until, false));
    }

    /** True while the consent is close enough to lapsing to say something. */
    public function consentEndsSoon(): bool
    {
        $left = $this->consentDaysLeft();

        return $left !== null && $left <= self::WARN_DAYS;
    }

    /**
     * The account handle used for pulling.
     *
     * Prefers the account matching the stored IBAN, otherwise the first one. A
     * consent often covers several accounts (current plus savings), and pulling
     * whichever came first would silently import the wrong one.
     */
    public function accountUid(): ?string
    {
        $accounts = is_array($this->accounts) ? $this->accounts : [];

        if (filled($this->iban)) {
            foreach ($accounts as $account) {
                if (($account['iban'] ?? null) === $this->iban) {
                    return $account['uid'] ?? null;
                }
            }
        }

        return $accounts[0]['uid'] ?? null;
    }

    /**
     * Issues a one-shot state token for the redirect round trip.
     *
     * Short-lived on purpose: it exists only for the seconds between "go to your
     * bank" and coming back. A token left lying around could later be redeemed
     * together with someone else's code.
     */
    public function issueState(): string
    {
        $state = bin2hex(random_bytes(16));

        $this->forceFill([
            'pending_state' => $state,
            'pending_state_expires_at' => now()->addMinutes(30),
        ])->save();

        return $state;
    }

    /**
     * Consumes the state; false when it is unknown, spent or stale.
     *
     * BOTH VALUES ARE READ BEFORE THE RESET. Reading the deadline afterwards
     * yields null - and "null means no deadline" would then wave every expired
     * token through. The first draft did exactly that.
     *
     * THE TOKEN IS SPENT EVEN WHEN IT DOES NOT MATCH. That is the point of a
     * one-shot value: a mismatch is either an error or an attempt, and neither
     * deserves a second try with the same token.
     */
    public function consumeState(?string $state): bool
    {
        $expected = $this->pending_state;
        $expiresAt = $this->pending_state_expires_at;

        $this->forceFill(['pending_state' => null, 'pending_state_expires_at' => null])->save();

        if (blank($expected) || blank($state) || ! hash_equals($expected, $state)) {
            return false;
        }

        return $expiresAt === null || $expiresAt->isFuture();
    }
}
