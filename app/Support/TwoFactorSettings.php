<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The settings of the unlock PIN and of confirmed devices (config
 * auth.two_factor), each checked against its limits. A value outside them
 * stops with a message that names the variable, the allowed range and what
 * the setting does, so a typo in the .env never turns into a silent default.
 */
final class TwoFactorSettings
{
    /** Longest PIN the format allows. */
    public const PIN_MAX_LENGTH = 12;

    public static function pinMinLength(): int
    {
        return self::integer('pin_min_length', 'TWO_FACTOR_PIN_MIN_LENGTH', 4, self::PIN_MAX_LENGTH,
            'kürzeste erlaubte PIN in Ziffern');
    }

    public static function pinMaxAttempts(): int
    {
        return self::integer('pin_max_attempts', 'TWO_FACTOR_PIN_MAX_ATTEMPTS', 1, 20,
            'falsche PIN-Eingaben in Folge, danach gilt die PIN erst nach einer Anmeldung mit dem Code aus der App wieder');
    }

    public static function trustedDeviceDays(): int
    {
        return self::integer('trusted_device_days', 'TWO_FACTOR_TRUSTED_DEVICE_DAYS', 1, 400,
            'Tage, die ein Gerät nach dem letzten Code aus der App als bestätigt gilt');
    }

    public static function trustedDeviceCookie(): string
    {
        $value = config('auth.two_factor.trusted_device_cookie');

        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value)) {
            throw new InvalidArgumentException(sprintf(
                'Die Einstellung TWO_FACTOR_TRUSTED_DEVICE_COOKIE hat den Wert „%s“. Erlaubt sind 1 bis 64 Zeichen aus '
                . 'Buchstaben, Ziffern, „_“ und „-“ (Name des Cookies, das ein bestätigtes Gerät markiert). '
                . 'Wert in der .env korrigieren und TxWatch neu starten.',
                is_scalar($value) ? $value : gettype($value),
            ));
        }

        return $value;
    }

    private static function integer(string $key, string $variable, int $min, int $max, string $effect): int
    {
        $value = config("auth.two_factor.{$key}");

        if (is_string($value)) {
            $value = trim($value);
        }

        if (! is_int($value) && ! (is_string($value) && preg_match('/^\d+$/', $value))) {
            $value = null;
        }

        if ($value === null || (int) $value < $min || (int) $value > $max) {
            $shown = config("auth.two_factor.{$key}");

            throw new InvalidArgumentException(sprintf(
                'Die Einstellung %s hat den Wert „%s“. Erlaubt ist eine ganze Zahl von %d bis %d (%s). '
                . 'Wert in der .env korrigieren und TxWatch neu starten.',
                $variable, is_scalar($shown) ? $shown : gettype($shown), $min, $max, $effect,
            ));
        }

        return (int) $value;
    }
}
