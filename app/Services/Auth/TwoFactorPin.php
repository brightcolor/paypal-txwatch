<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\TwoFactorSettings;
use Illuminate\Support\Facades\Hash;

/**
 * The unlock PIN of a two-factor account: set, remove, check. Wrong entries
 * are counted in a row; at the limit (TwoFactorSettings::pinMaxAttempts) the
 * PIN stops working until the next sign-in with the authenticator code, which
 * calls resetFailures().
 */
class TwoFactorPin
{
    public function set(User $user, string $pin): void
    {
        $user->forceFill([
            'two_factor_pin' => $pin,
            'two_factor_pin_set_at' => now(),
            'two_factor_pin_failures' => 0,
        ])->save();
    }

    public function remove(User $user): void
    {
        $user->forceFill([
            'two_factor_pin' => null,
            'two_factor_pin_set_at' => null,
            'two_factor_pin_failures' => 0,
        ])->save();
    }

    public function isLocked(User $user): bool
    {
        return $user->two_factor_pin_failures >= TwoFactorSettings::pinMaxAttempts();
    }

    public function remainingAttempts(User $user): int
    {
        return max(0, TwoFactorSettings::pinMaxAttempts() - $user->two_factor_pin_failures);
    }

    public function attempt(User $user, string $pin): PinCheck
    {
        if (! $user->hasTwoFactorPin() || $this->isLocked($user)) {
            return PinCheck::Locked;
        }

        if (Hash::check($pin, $user->two_factor_pin)) {
            $this->resetFailures($user);

            return PinCheck::Accepted;
        }

        $user->increment('two_factor_pin_failures');

        return $this->isLocked($user) ? PinCheck::Locked : PinCheck::Rejected;
    }

    public function resetFailures(User $user): void
    {
        if ($user->two_factor_pin_failures !== 0) {
            $user->forceFill(['two_factor_pin_failures' => 0])->save();
        }
    }

    /**
     * The PINs anyone tries first: one digit repeated, or a run up or down
     * such as 123456 or 987654.
     */
    public static function isGuessable(string $pin): bool
    {
        if (preg_match('/^(\d)\1*$/', $pin)) {
            return true;
        }

        $digits = array_map('intval', str_split($pin));

        foreach ([1, -1] as $step) {
            $run = true;
            for ($i = 1, $n = count($digits); $i < $n; $i++) {
                if ($digits[$i] - $digits[$i - 1] !== $step) {
                    $run = false;
                    break;
                }
            }
            if ($run) {
                return true;
            }
        }

        return false;
    }
}
