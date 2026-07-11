<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\TwoFactorAuthSettings;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['auth.two_factor_required_for_admins' => true]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Role::findByName('admin'));

        return $user;
    }

    public function test_admin_without_two_factor_is_forced_to_the_settings_page(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin');

        $response->assertRedirect(TwoFactorAuthSettings::getUrl());
    }

    public function test_admin_can_still_reach_the_settings_page_to_enroll(): void
    {
        $response = $this->actingAs($this->admin())->get(TwoFactorAuthSettings::getUrl());

        $response->assertOk();
    }

    public function test_admin_with_two_factor_is_not_forced(): void
    {
        $service = new TwoFactorAuthenticationService(new Google2FA());
        $admin = $this->admin();
        $service->enable($admin, $service->generateSecretKey(), $service->generateRecoveryCodes());

        // Enabled but session challenge not passed -> goes to challenge, not the settings page.
        $response = $this->actingAs($admin)->get('/admin');

        $response->assertRedirect(route('two-factor.challenge'));
    }

    public function test_non_admin_is_not_forced(): void
    {
        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole(Role::findByName('manager'));

        $this->actingAs($manager)->get('/admin')->assertOk();
    }

    public function test_enforcement_can_be_disabled_by_config(): void
    {
        config(['auth.two_factor_required_for_admins' => false]);

        $this->actingAs($this->admin())->get('/admin')->assertOk();
    }
}
