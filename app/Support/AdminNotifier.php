<?php

namespace App\Support;

use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Fans a problem notification out to every admin via the Filament bell
 * (database notifications - no SMTP required) and mirrors it to the log.
 * Safe to call from queued jobs. Mail can be layered on later once SMTP is
 * configured; the bell is the reliable channel today.
 */
class AdminNotifier
{
    public static function warn(string $title, string $body, ?string $url = null): void
    {
        Log::warning("[Admin] {$title}: {$body}");

        $admins = User::query()->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

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
}
