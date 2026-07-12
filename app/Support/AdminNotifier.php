<?php

namespace App\Support;

use App\Models\MailSetting;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Fans a problem notification out to every admin via the Filament bell
 * (database notifications - always available) and mirrors it to the log. When
 * SMTP is configured (Einstellungen → E-Mail-Versand) it additionally emails
 * the alert recipients. Safe to call from queued jobs.
 */
class AdminNotifier
{
    public static function warn(string $title, string $body, ?string $url = null): void
    {
        Log::warning("[Admin] {$title}: {$body}");

        $admins = User::query()->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->get();

        if ($admins->isNotEmpty()) {
            $notification = Notification::make()
                ->title($title)
                ->body($body)
                ->danger()
                ->icon('heroicon-o-exclamation-triangle');

            if ($url) {
                $notification->actions([
                    Action::make('open')->label('Öffnen')->url($url)->markAsRead(),
                ]);
            }

            $notification->sendToDatabase($admins);
        }

        static::mail($title, $body, $url);
    }

    /** Best-effort email mirror; only fires when SMTP is configured. */
    private static function mail(string $title, string $body, ?string $url): void
    {
        try {
            $setting = MailSetting::current();
            if (! $setting->isConfigured()) {
                return;
            }

            $recipients = $setting->alertRecipientList();
            if (empty($recipients)) {
                return;
            }

            // SMTP config is already applied at boot (AppServiceProvider); no
            // need to re-apply here.
            $text = $body . ($url ? "\n\n{$url}" : '');

            Mail::raw($text, function ($m) use ($recipients, $title) {
                $m->to($recipients)->subject("TxWatch – {$title}");
            });
        } catch (\Throwable $e) {
            // Email is a best-effort mirror; the bell already carries the alert.
            Log::warning('AdminNotifier mail failed: ' . $e->getMessage());
        }
    }
}
