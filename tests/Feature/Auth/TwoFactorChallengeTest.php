<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    private function serviceAndSecret(): array
    {
        $google2fa = new Google2FA();
        $service = new TwoFactorAuthenticationService($google2fa);

        return [$service, $google2fa];
    }

    public function test_user_without_two_factor_enabled_is_never_challenged(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();
    }

    public function test_user_with_two_factor_enabled_is_redirected_to_challenge(): void
    {
        [$service] = $this->serviceAndSecret();
        $user = User::factory()->create();
        $service->enable($user, $service->generateSecretKey(), $service->generateRecoveryCodes());

        $response = $this->actingAs($user)->get('/admin');

        $response->assertRedirect(route('two-factor.challenge'));
    }

    public function test_valid_totp_code_grants_access_for_the_session(): void
    {
        [$service, $google2fa] = $this->serviceAndSecret();
        $user = User::factory()->create();
        $secret = $service->generateSecretKey();
        $service->enable($user, $secret, $service->generateRecoveryCodes());

        $code = $google2fa->getCurrentOtp($secret);

        $verify = $this->actingAs($user)->post(route('two-factor.verify'), ['code' => $code]);
        $verify->assertRedirect();

        $dashboard = $this->get('/admin');
        $dashboard->assertOk();
    }

    public function test_invalid_code_is_rejected(): void
    {
        [$service] = $this->serviceAndSecret();
        $user = User::factory()->create();
        $service->enable($user, $service->generateSecretKey(), $service->generateRecoveryCodes());

        $response = $this->actingAs($user)->post(route('two-factor.verify'), ['code' => '000000']);

        $response->assertSessionHasErrors('code');
    }

    public function test_recovery_code_can_be_used_instead_of_totp_and_is_then_consumed(): void
    {
        [$service] = $this->serviceAndSecret();
        $user = User::factory()->create();
        $codes = $service->generateRecoveryCodes();
        $service->enable($user, $service->generateSecretKey(), $codes);

        $this->actingAs($user)
            ->post(route('two-factor.verify'), ['code' => $codes[0]])
            ->assertRedirect();

        $this->assertNotContains($codes[0], $user->fresh()->two_factor_recovery_codes);
    }
}
