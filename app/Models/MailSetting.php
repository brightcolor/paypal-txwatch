<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * Single-row SMTP configuration editable in the panel (Einstellungen →
 * E-Mail-Versand). Lets the operator turn on real email without redeploying.
 * The password is encrypted at rest. When enabled, apply() pushes the values
 * into Laravel's mail config at runtime so Mail::/notifications use them.
 */
class MailSetting extends Model
{
    protected $fillable = [
        'enabled', 'host', 'port', 'encryption', 'username', 'password',
        'from_address', 'from_name', 'alert_recipients',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'password' => 'encrypted',
        ];
    }

    /** The single settings row, created empty on first access. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function isConfigured(): bool
    {
        return $this->enabled && filled($this->host) && filled($this->from_address);
    }

    /** Recipients for admin alert mails: explicit list, else active admins' emails. */
    public function alertRecipientList(): array
    {
        if (filled($this->alert_recipients)) {
            return collect(preg_split('/[,;\s]+/', $this->alert_recipients))
                ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
                ->values()->all();
        }

        return User::query()->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->pluck('email')->filter()->values()->all();
    }

    /** Push the stored SMTP config into the live mail config (no-op if not configured). */
    public function apply(): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $this->host);
        Config::set('mail.mailers.smtp.port', $this->port);
        Config::set('mail.mailers.smtp.username', $this->username);
        Config::set('mail.mailers.smtp.password', $this->password);
        Config::set('mail.mailers.smtp.encryption', $this->encryption ?: null);
        Config::set('mail.from.address', $this->from_address);
        Config::set('mail.from.name', $this->from_name ?: config('app.name'));
    }
}
