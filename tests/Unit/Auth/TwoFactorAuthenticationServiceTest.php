<?php

namespace Tests\Unit\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TwoFactorAuthenticationService
    {
        return new TwoFactorAuthenticationService(new Google2FA());
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    public function test_it_generates_a_valid_secret_key(): void
    {
        $secret = $this->service()->generateSecretKey();

        $this->assertNotEmpty($secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function test_verify_code_accepts_the_current_valid_totp(): void
    {
        $google2fa = new Google2FA();
        $service = new TwoFactorAuthenticationService($google2fa);
        $secret = $service->generateSecretKey();

        $currentCode = $google2fa->getCurrentOtp($secret);

        $this->assertTrue($service->verifyCode($secret, $currentCode));
    }

    public function test_verify_code_rejects_an_invalid_code(): void
    {
        $service = $this->service();
        $secret = $service->generateSecretKey();

        $this->assertFalse($service->verifyCode($secret, '000000'));
    }

    public function test_generate_recovery_codes_returns_ten_unique_codes(): void
    {
        $codes = $this->service()->generateRecoveryCodes();

        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
    }

    public function test_enable_persists_secret_and_recovery_codes_and_marks_confirmed(): void
    {
        $user = $this->user();
        $service = $this->service();
        $secret = $service->generateSecretKey();
        $codes = $service->generateRecoveryCodes();

        $service->enable($user, $secret, $codes);
        $user->refresh();

        $this->assertTrue($user->hasTwoFactorEnabled());
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertSame($codes, $user->two_factor_recovery_codes);
    }

    public function test_disable_clears_all_two_factor_state(): void
    {
        $user = $this->user();
        $service = $this->service();
        $service->enable($user, $service->generateSecretKey(), $service->generateRecoveryCodes());

        $service->disable($user);
        $user->refresh();

        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_recovery_code_is_single_use(): void
    {
        $user = $this->user();
        $service = $this->service();
        $codes = $service->generateRecoveryCodes();
        $service->enable($user, $service->generateSecretKey(), $codes);

        $codeToUse = $codes[3];

        $this->assertTrue($service->verifyAndConsumeRecoveryCode($user, $codeToUse));
        $this->assertFalse($service->verifyAndConsumeRecoveryCode($user->fresh(), $codeToUse));
    }

    public function test_unknown_recovery_code_is_rejected(): void
    {
        $user = $this->user();
        $service = $this->service();
        $service->enable($user, $service->generateSecretKey(), $service->generateRecoveryCodes());

        $this->assertFalse($service->verifyAndConsumeRecoveryCode($user, 'not-a-real-code'));
    }
}
