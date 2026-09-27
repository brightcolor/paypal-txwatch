<?php

namespace Tests\Feature\Auth;

use App\Support\TwoFactorSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The PIN and device settings accept values inside their limits, also as the
 * strings an .env delivers, and stop with a message that names the variable.
 */
class TwoFactorSettingsTest extends TestCase
{
    public function test_defaults(): void
    {
        $this->assertSame(6, TwoFactorSettings::pinMinLength());
        $this->assertSame(5, TwoFactorSettings::pinMaxAttempts());
        $this->assertSame(30, TwoFactorSettings::trustedDeviceDays());
        $this->assertSame('txwatch_2fa_device', TwoFactorSettings::trustedDeviceCookie());
    }

    public function test_values_from_the_env_arrive_as_strings(): void
    {
        config([
            'auth.two_factor.pin_min_length' => '8',
            'auth.two_factor.pin_max_attempts' => ' 3 ',
            'auth.two_factor.trusted_device_days' => '90',
            'auth.two_factor.trusted_device_cookie' => 'geraet-ok_1',
        ]);

        $this->assertSame(8, TwoFactorSettings::pinMinLength());
        $this->assertSame(3, TwoFactorSettings::pinMaxAttempts());
        $this->assertSame(90, TwoFactorSettings::trustedDeviceDays());
        $this->assertSame('geraet-ok_1', TwoFactorSettings::trustedDeviceCookie());
    }

    #[DataProvider('invalidValues')]
    public function test_values_outside_the_limits_name_the_variable(string $key, mixed $value, string $method, string $expected): void
    {
        config(["auth.two_factor.{$key}" => $value]);

        try {
            TwoFactorSettings::{$method}();
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
            'PIN zu kurz' => ['pin_min_length', 3, 'pinMinLength', 'TWO_FACTOR_PIN_MIN_LENGTH hat den Wert „3“. Erlaubt ist eine ganze Zahl von 4 bis 12'],
            'PIN zu lang' => ['pin_min_length', '13', 'pinMinLength', 'von 4 bis 12'],
            'Versuche null' => ['pin_max_attempts', 0, 'pinMaxAttempts', 'TWO_FACTOR_PIN_MAX_ATTEMPTS hat den Wert „0“. Erlaubt ist eine ganze Zahl von 1 bis 20'],
            'Versuche Text' => ['pin_max_attempts', 'fünf', 'pinMaxAttempts', 'hat den Wert „fünf“'],
            'Tage negativ' => ['trusted_device_days', '-1', 'trustedDeviceDays', 'TWO_FACTOR_TRUSTED_DEVICE_DAYS hat den Wert „-1“. Erlaubt ist eine ganze Zahl von 1 bis 400'],
            'Tage Komma' => ['trusted_device_days', '1.5', 'trustedDeviceDays', 'von 1 bis 400'],
            'Cookie mit Leerzeichen' => ['trusted_device_cookie', 'mein cookie', 'trustedDeviceCookie', 'TWO_FACTOR_TRUSTED_DEVICE_COOKIE hat den Wert „mein cookie“'],
            'Cookie leer' => ['trusted_device_cookie', '', 'trustedDeviceCookie', 'Erlaubt sind 1 bis 64 Zeichen'],
        ];
    }
}
