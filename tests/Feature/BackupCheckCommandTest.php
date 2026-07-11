<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BackupCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = storage_path('app/last-backup-at');
        @unlink($this->marker);

        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findByName('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    public function test_missing_marker_warns_admins(): void
    {
        $this->artisan('backup:check')->assertSuccessful();

        $this->assertSame(1, \DB::table('notifications')->count());
    }

    public function test_stale_marker_warns_admins(): void
    {
        file_put_contents($this->marker, (string) now()->subHours(48)->timestamp);

        $this->artisan('backup:check')->assertSuccessful();

        $this->assertSame(1, \DB::table('notifications')->count());
    }

    public function test_fresh_marker_does_not_warn(): void
    {
        file_put_contents($this->marker, (string) now()->subHours(2)->timestamp);

        $this->artisan('backup:check')->assertSuccessful();

        $this->assertSame(0, \DB::table('notifications')->count());
    }
}
