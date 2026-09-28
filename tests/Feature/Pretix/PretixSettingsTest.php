<?php

namespace Tests\Feature\Pretix;

use App\Services\Pretix\PretixSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pretix settings accept values inside their limits, also as the strings
 * an .env delivers, and stop with a message that names the variable.
 */
class PretixSettingsTest extends TestCase
{
    public function test_defaults(): void
    {
        $this->assertSame(600, PretixSettings::ticketStatsCacheSeconds());
        $this->assertSame(20, PretixSettings::httpTimeout());
    }

    public function test_values_from_the_env_arrive_as_strings(): void
    {
        config([
            'pretix.ticket_stats_cache_seconds' => '0',
            'pretix.http_timeout' => ' 45 ',
        ]);

        $this->assertSame(0, PretixSettings::ticketStatsCacheSeconds());
        $this->assertSame(45, PretixSettings::httpTimeout());
    }

    #[DataProvider('invalidValues')]
    public function test_values_outside_the_limits_name_the_variable(string $key, mixed $value, string $method, string $expected): void
    {
        config(["pretix.{$key}" => $value]);

        try {
            PretixSettings::{$method}();
            $this->fail("{$key} = " . var_export($value, true) . ' wurde angenommen.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
            $this->assertStringContainsString('TxWatch neu starten', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: string, 3: string}>
     */
    public static function invalidValues(): array
    {
        return [
            'Cache negativ' => ['ticket_stats_cache_seconds', '-1', 'ticketStatsCacheSeconds',
                'PRETIX_TICKET_STATS_CACHE_SECONDS hat den Wert „-1“. Erlaubt ist eine ganze Zahl von 0 bis 86400'],
            'Cache zu lang' => ['ticket_stats_cache_seconds', 86401, 'ticketStatsCacheSeconds', 'von 0 bis 86400'],
            'Cache Text' => ['ticket_stats_cache_seconds', 'zehn Minuten', 'ticketStatsCacheSeconds', 'hat den Wert „zehn Minuten“'],
            'Zeitlimit null' => ['http_timeout', 0, 'httpTimeout',
                'PRETIX_HTTP_TIMEOUT hat den Wert „0“. Erlaubt ist eine ganze Zahl von 1 bis 300'],
            'Zeitlimit zu lang' => ['http_timeout', '301', 'httpTimeout', 'von 1 bis 300'],
            'Zeitlimit Komma' => ['http_timeout', '2.5', 'httpTimeout', 'hat den Wert „2.5“'],
        ];
    }
}
