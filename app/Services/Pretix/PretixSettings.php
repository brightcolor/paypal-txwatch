<?php

namespace App\Services\Pretix;

use InvalidArgumentException;

/**
 * The pretix settings (config pretix), each checked against its limits. A value
 * outside them stops with a message that names the variable, the allowed range
 * and what the setting does, so a typo in the .env never turns into a silent default.
 */
final class PretixSettings
{
    public static function ticketStatsCacheSeconds(): int
    {
        return self::integer('ticket_stats_cache_seconds', 'PRETIX_TICKET_STATS_CACHE_SECONDS', 0, 86400,
            'Sekunden, die die Ticket-Statistik einer Verbindung zwischengespeichert bleibt; 0 schaltet den Zwischenspeicher ab');
    }

    public static function httpTimeout(): int
    {
        return self::integer('http_timeout', 'PRETIX_HTTP_TIMEOUT', 1, 300,
            'Sekunden, die eine Anfrage an pretix dauern darf, bevor sie als Zeitüberschreitung gilt');
    }

    private static function integer(string $key, string $variable, int $min, int $max, string $effect): int
    {
        $value = config("pretix.{$key}");

        if (is_string($value)) {
            $value = trim($value);
        }

        if (! is_int($value) && ! (is_string($value) && preg_match('/^\d+$/', $value))) {
            $value = null;
        }

        if ($value === null || (int) $value < $min || (int) $value > $max) {
            $shown = config("pretix.{$key}");

            throw new InvalidArgumentException(sprintf(
                'Die Einstellung %s hat den Wert „%s“. Erlaubt ist eine ganze Zahl von %d bis %d (%s). '
                . 'Wert in der .env korrigieren und TxWatch neu starten.',
                $variable, is_scalar($shown) ? $shown : gettype($shown), $min, $max, $effect,
            ));
        }

        return (int) $value;
    }
}
