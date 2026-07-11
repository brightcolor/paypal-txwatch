<?php

namespace App\Listeners;

use App\Models\LoginEvent;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records successful logins and failed attempts into login_events. Registered
 * for both Illuminate\Auth\Events\Login and \Failed in AppServiceProvider.
 * Never throws - a logging failure must not block or break authentication.
 */
class RecordLoginEvent
{
    public function handleLogin(Login $event): void
    {
        $this->record(
            userId: $event->user->getAuthIdentifier(),
            email: $event->user->email ?? null,
            successful: true,
        );
    }

    public function handleFailed(Failed $event): void
    {
        $this->record(
            userId: $event->user?->getAuthIdentifier(),
            email: $event->credentials['email'] ?? null,
            successful: false,
        );
    }

    private function record(?int $userId, ?string $email, bool $successful): void
    {
        try {
            $request = request();

            LoginEvent::create([
                'user_id' => $userId,
                'email' => $email ? Str::limit($email, 255, '') : null,
                'successful' => $successful,
                'ip' => $request?->ip(),
                'user_agent' => Str::limit((string) $request?->userAgent(), 512, ''),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Never let auditing break the auth flow.
        }
    }
}
