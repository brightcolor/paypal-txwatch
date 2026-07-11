<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AdminNotifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_warn_writes_a_database_notification_to_active_admins_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findByName('admin'));

        $inactiveAdmin = User::factory()->create(['is_active' => false]);
        $inactiveAdmin->assignRole(Role::findByName('admin'));

        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole(Role::findByName('manager'));

        AdminNotifier::warn('Testfehler', 'Etwas ist schiefgelaufen', 'https://x/');

        $this->assertSame(1, \DB::table('notifications')->count());
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $admin->id,
            'notifiable_type' => User::class,
        ]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $manager->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $inactiveAdmin->id]);
    }

    public function test_warn_is_safe_with_no_admins(): void
    {
        AdminNotifier::warn('X', 'Y'); // must not throw
        $this->assertSame(0, \DB::table('notifications')->count());
    }
}
