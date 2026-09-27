<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\TwoFactorSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Marks a browser as confirmed once an authenticator code was entered in it.
 * Only a confirmed device accepts the unlock PIN, so a PIN learned together
 * with the password never stands in for the code on someone else's device.
 *
 * The cookie holds the user id, the user's device counter and the time of the
 * confirmation; the web middleware encrypts and signs it. Moving the counter
 * on (a locked PIN, 2FA switched off, "forget the other devices") invalidates
 * every cookie issued before.
 */
class TrustedDevice
{
    public function trust(User $user): void
    {
        Cookie::queue(Cookie::make(
            TwoFactorSettings::trustedDeviceCookie(),
            json_encode([
                'user' => (string) $user->getKey(),
                'epoch' => (int) $user->two_factor_device_epoch,
                'at' => now()->getTimestamp(),
            ]),
            TwoFactorSettings::trustedDeviceDays() * 24 * 60,
        ));
    }

    public function isTrusted(User $user, Request $request): bool
    {
        $data = json_decode((string) $request->cookie(TwoFactorSettings::trustedDeviceCookie()), true);

        if (! is_array($data) || ! is_int($data['at'] ?? null)) {
            return false;
        }

        $oldest = now()->subDays(TwoFactorSettings::trustedDeviceDays())->getTimestamp();

        return ($data['user'] ?? null) === (string) $user->getKey()
            && ($data['epoch'] ?? null) === (int) $user->two_factor_device_epoch
            && $data['at'] >= $oldest
            && $data['at'] <= now()->getTimestamp();
    }

    /** Every confirmed device of the user needs the app code again. */
    public function forgetAll(User $user): void
    {
        $user->increment('two_factor_device_epoch');
    }

    /** Every other device needs the app code again; this one stays confirmed. */
    public function forgetOthers(User $user): void
    {
        $this->forgetAll($user);
        $this->trust($user);
    }
}
