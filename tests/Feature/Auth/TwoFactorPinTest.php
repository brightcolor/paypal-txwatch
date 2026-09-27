<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\TwoFactorAuthSettings;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticationService;
use App\Services\Auth\TwoFactorPin;
use App\Support\TwoFactorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Signing out from the challenge, the return through "Angemeldet bleiben"
 * (unlock PIN on a confirmed device, otherwise a complete sign-out) and the
 * PIN on the settings page.
 */
class TwoFactorPinTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '482913';

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->google2fa = new Google2FA();
    }

    public function test_signing_out_works_from_the_challenge_page(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->actingAs($user)->get('/admin')->assertRedirect(route('two-factor.challenge'));
        $this->get(route('two-factor.challenge'))
            ->assertOk()
            ->assertSee('action="' . route('filament.admin.auth.logout') . '"', false);

        $this->post(route('filament.admin.auth.logout'))->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_return_without_pin_signs_out_completely(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->withCookies($this->rememberCookie($user))->get('/admin')
            ->assertRedirect(route('two-factor.challenge'));
        $this->freshRequest();

        $response = $this->get(route('two-factor.challenge'));

        $response->assertRedirect('/admin/login');
        $response->assertCookieExpired(Auth::guard('web')->getRecallerName());
        $this->assertGuest();
        $this->assertNotificationTitle('Sitzung abgelaufen');
    }

    public function test_return_with_pin_on_a_confirmed_device_asks_for_the_pin(): void
    {
        [$user] = $this->userWithTwoFactor();
        app(TwoFactorPin::class)->set($user, self::PIN);

        $this->withCookies($this->rememberCookie($user) + $this->deviceCookie($user))->get('/admin/transactions')
            ->assertRedirect(route('two-factor.challenge'));
        $this->freshRequest();

        $this->get(route('two-factor.challenge'))
            ->assertOk()
            ->assertSee('PIN eingeben')
            ->assertSee('name="pin"', false);

        $this->post(route('two-factor.verify'), ['pin' => self::PIN])->assertRedirect('/admin/transactions');
        $this->get('/admin')->assertOk();
    }

    public function test_return_with_pin_on_an_unconfirmed_device_signs_out_completely(): void
    {
        [$user] = $this->userWithTwoFactor();
        app(TwoFactorPin::class)->set($user, self::PIN);

        $this->withCookies($this->rememberCookie($user))->get('/admin');
        $this->freshRequest();

        $this->get(route('two-factor.challenge'))->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_device_confirmation_ends_after_the_configured_days(): void
    {
        config(['auth.two_factor.trusted_device_days' => 7]);
        [$user] = $this->userWithTwoFactor();
        app(TwoFactorPin::class)->set($user, self::PIN);

        $this->actingAs($user)
            ->withCookies($this->deviceCookie($user, now()->subDays(6)->getTimestamp()))
            ->get(route('two-factor.challenge'))
            ->assertSee('name="pin"', false);

        $this->withCookies($this->deviceCookie($user, now()->subDays(8)->getTimestamp()))
            ->get(route('two-factor.challenge'))
            ->assertDontSee('name="pin"', false)
            ->assertSee('name="code"', false);
    }

    public function test_pin_is_refused_on_an_unconfirmed_device(): void
    {
        [$user] = $this->userWithTwoFactor();
        app(TwoFactorPin::class)->set($user, self::PIN);

        $this->actingAs($user)->get(route('two-factor.challenge'))
            ->assertSee('name="code"', false)
            ->assertDontSee('name="pin"', false);

        $this->post(route('two-factor.verify'), ['pin' => self::PIN])
            ->assertRedirect(route('two-factor.challenge', ['via' => 'code']))
            ->assertSessionHasErrors('code');
        $this->get('/admin')->assertRedirect(route('two-factor.challenge'));
    }

    public function test_wrong_pins_count_down_and_lock_at_the_configured_limit(): void
    {
        config(['auth.two_factor.pin_max_attempts' => 3]);
        [$user] = $this->userWithTwoFactor();
        app(TwoFactorPin::class)->set($user, self::PIN);
        $this->actingAs($user)->withCookies($this->deviceCookie($user));

        $this->post(route('two-factor.verify'), ['pin' => '111222'])
            ->assertSessionHasErrors(['pin' => 'Die PIN stimmt nicht. Du hast noch 2 Versuche.']);
        $this->post(route('two-factor.verify'), ['pin' => '111222'])
            ->assertSessionHasErrors(['pin' => 'Die PIN stimmt nicht. Du hast noch einen Versuch, danach brauchst du den Code aus deiner Authenticator-App.']);

        $this->post(route('two-factor.verify'), ['pin' => '111222'])->assertRedirect('/admin/login');

        $this->assertGuest();
        $this->assertNotificationTitle('PIN gesperrt');
        $fresh = $user->fresh();
        $this->assertSame(3, $fresh->two_factor_pin_failures);
        $this->assertSame(1, $fresh->two_factor_device_epoch, 'Nach der Sperre gilt kein Gerät mehr als bestätigt.');
    }

    public function test_locked_pin_refuses_even_the_right_pin(): void
    {
        config(['auth.two_factor.pin_max_attempts' => 2]);
        [$user] = $this->userWithTwoFactor();
        app(TwoFactorPin::class)->set($user, self::PIN);
        $user->forceFill(['two_factor_pin_failures' => 2])->save();

        $this->actingAs($user)->withCookies($this->deviceCookie($user))
            ->get(route('two-factor.challenge'))
            ->assertDontSee('name="pin"', false);

        $this->post(route('two-factor.verify'), ['pin' => self::PIN])->assertSessionHasErrors('code');
        $this->get('/admin')->assertRedirect(route('two-factor.challenge'));
    }

    public function test_app_code_confirms_the_device_and_unlocks_the_pin(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        app(TwoFactorPin::class)->set($user, self::PIN);
        $user->forceFill(['two_factor_pin_failures' => 5])->save();

        $response = $this->actingAs($user)
            ->post(route('two-factor.verify'), ['code' => $this->google2fa->getCurrentOtp($secret)]);

        $response->assertRedirect();
        $response->assertCookie(TwoFactorSettings::trustedDeviceCookie());
        $this->assertSame(0, $user->fresh()->two_factor_pin_failures);
    }

    public function test_pin_can_be_set_changed_and_removed_on_the_settings_page(): void
    {
        [$user] = $this->userWithTwoFactor();
        $this->actingAs($user);

        Livewire::test(TwoFactorAuthSettings::class)
            ->set('pinData.pin', self::PIN)
            ->set('pinData.pin_confirmation', self::PIN)
            ->call('savePin')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check(self::PIN, $user->fresh()->two_factor_pin));
        $this->assertTrue(Cookie::hasQueued(TwoFactorSettings::trustedDeviceCookie()), 'Das Gerät, auf dem die PIN entsteht, gilt als bestätigt.');

        Livewire::test(TwoFactorAuthSettings::class)
            ->set('pinData.pin', '750316')
            ->set('pinData.pin_confirmation', '750316')
            ->call('savePin');
        $this->assertTrue(Hash::check('750316', $user->fresh()->two_factor_pin));

        Livewire::test(TwoFactorAuthSettings::class)->call('removePin');
        $this->assertFalse($user->fresh()->hasTwoFactorPin());
    }

    public function test_pin_rules_follow_the_configured_minimum_length(): void
    {
        config(['auth.two_factor.pin_min_length' => 8]);
        [$user] = $this->userWithTwoFactor();
        $this->actingAs($user);

        $cases = [
            'zu kurz' => ['482913', '482913', 'Die PIN besteht aus 8 bis 12 Ziffern, ohne Buchstaben oder Leerzeichen.'],
            'Buchstaben' => ['48291a37', '48291a37', 'Die PIN besteht aus 8 bis 12 Ziffern, ohne Buchstaben oder Leerzeichen.'],
            'Folge' => ['23456789', '23456789', 'Diese PIN ist zu leicht zu erraten. Nimm keine gleichen Ziffern und keine Folge wie 123456.'],
            'gleiche Ziffern' => ['77777777', '77777777', 'Diese PIN ist zu leicht zu erraten. Nimm keine gleichen Ziffern und keine Folge wie 123456.'],
        ];

        foreach ($cases as $case => [$pin, $confirmation, $message]) {
            $errors = Livewire::test(TwoFactorAuthSettings::class)
                ->set('pinData.pin', $pin)
                ->set('pinData.pin_confirmation', $confirmation)
                ->call('savePin')
                ->errors();

            $this->assertContains($message, $errors->get('pinData.pin'), $case);
        }

        $errors = Livewire::test(TwoFactorAuthSettings::class)
            ->set('pinData.pin', '48291374')
            ->set('pinData.pin_confirmation', '48291375')
            ->call('savePin')
            ->errors();
        $this->assertContains('Die beiden Eingaben stimmen nicht überein. Gib die PIN zweimal gleich ein.', $errors->get('pinData.pin_confirmation'));

        Livewire::test(TwoFactorAuthSettings::class)
            ->set('pinData.pin', '48291374')
            ->set('pinData.pin_confirmation', '48291374')
            ->call('savePin')
            ->assertHasNoErrors();
        $this->assertTrue($user->fresh()->hasTwoFactorPin());
    }

    public function test_pin_needs_two_factor_to_be_on(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(TwoFactorAuthSettings::class)
            ->set('pinData.pin', self::PIN)
            ->set('pinData.pin_confirmation', self::PIN)
            ->call('savePin');

        $this->assertFalse($user->fresh()->hasTwoFactorPin());
    }

    public function test_forgetting_other_devices_keeps_this_one(): void
    {
        [$user] = $this->userWithTwoFactor();
        $this->actingAs($user);

        Livewire::test(TwoFactorAuthSettings::class)->call('forgetOtherDevices');

        $this->assertSame(1, $user->fresh()->two_factor_device_epoch);
        $this->assertTrue(Cookie::hasQueued(TwoFactorSettings::trustedDeviceCookie()));
    }

    public function test_switching_two_factor_off_removes_the_pin_and_every_confirmation(): void
    {
        [$user] = $this->userWithTwoFactor();
        app(TwoFactorPin::class)->set($user, self::PIN);

        app(TwoFactorAuthenticationService::class)->disable($user);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasTwoFactorPin());
        $this->assertSame(1, $fresh->two_factor_device_epoch);
    }

    public function test_device_cookie_of_another_user_or_counter_is_ignored(): void
    {
        [$user] = $this->userWithTwoFactor();
        [$other] = $this->userWithTwoFactor();
        app(TwoFactorPin::class)->set($user, self::PIN);

        $this->actingAs($user)->withCookies($this->deviceCookie($other))
            ->get(route('two-factor.challenge'))->assertDontSee('name="pin"', false);

        $this->withCookies($this->deviceCookie($user, epoch: 7))
            ->get(route('two-factor.challenge'))->assertDontSee('name="pin"', false);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function userWithTwoFactor(): array
    {
        $service = new TwoFactorAuthenticationService($this->google2fa);
        $user = User::factory()->create();
        $secret = $service->generateSecretKey();
        $service->enable($user, $secret, $service->generateRecoveryCodes());

        return [$user->fresh(), $secret];
    }

    /** The "Angemeldet bleiben" cookie, as the session guard writes it. */
    private function rememberCookie(User $user): array
    {
        return [
            Auth::guard('web')->getRecallerName() => $user->getAuthIdentifier() . '|' . $user->getRememberToken() . '|' . $user->getAuthPassword(),
        ];
    }

    private function deviceCookie(User $user, ?int $at = null, ?int $epoch = null): array
    {
        return [
            TwoFactorSettings::trustedDeviceCookie() => json_encode([
                'user' => (string) $user->getKey(),
                'epoch' => $epoch ?? (int) $user->two_factor_device_epoch,
                'at' => $at ?? now()->getTimestamp(),
            ]),
        ];
    }

    /** Next request resolves the user from the session, like a new page load. */
    private function freshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function assertNotificationTitle(string $title): void
    {
        $titles = collect(session('filament.notifications', []))->pluck('title')->all();

        $this->assertContains($title, $titles);
    }
}
