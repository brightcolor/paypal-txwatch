<?php

namespace Tests\Feature\Pretix;

use App\Models\PretixConnection;
use App\Models\PretixImportRun;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PretixImportRunListTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_runs_list_renders_with_a_logged_run(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));

        $connection = PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein', 'api_token' => 'x',
        ]);

        PretixImportRun::create([
            'pretix_connection_id' => $connection->id,
            'status' => PretixImportRun::STATUS_SUCCESS,
            'events_total' => 5, 'events_done' => 5, 'orders_imported' => 855,
            'matched' => 662, 'mismatch' => 0, 'unmatched' => 2,
            'log' => [['t' => '23:00:00', 'm' => 'Abgleich fertig']],
            'started_at' => now()->subMinute(), 'finished_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(\App\Filament\Resources\PretixImportRunResource::getUrl('index'))
            ->assertSuccessful();
    }
}
