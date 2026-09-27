<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The error pages a user meets on the two-factor step say in German what
 * happened and what to do next.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_too_many_code_attempts_show_when_to_try_again(): void
    {
        $service = new TwoFactorAuthenticationService(new Google2FA());
        $user = User::factory()->create();
        $service->enable($user, $service->generateSecretKey(), $service->generateRecoveryCodes());
        $this->actingAs($user);

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        }

        $this->post(route('two-factor.verify'), ['code' => '000000'])
            ->assertStatus(429)
            ->assertSee('Zu viele Versuche')
            ->assertSeeText('Sekunden erneut');
    }

    public function test_expired_form_says_to_reload(): void
    {
        Route::middleware('web')->post('/_test/abgelaufen', fn () => throw new TokenMismatchException());

        $this->post('/_test/abgelaufen')
            ->assertStatus(419)
            ->assertSee('Seite abgelaufen')
            ->assertSee('Seite neu laden');
    }
}
