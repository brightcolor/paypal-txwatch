<?php

namespace Tests\Feature;

use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Guards the pagination policy: no "Alle" option (it loads the whole table and
 * kills the server), sizes up to 500, and a remembered large size is reset to
 * 200 on reload so we don't loop on a slow query.
 */
class PaginationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('admin'));

        return $user;
    }

    public function test_page_options_have_no_all_and_cap_at_500(): void
    {
        $this->actingAs($this->admin());

        $table = Livewire::test(ListTransactions::class)->instance()->getTable();
        $options = $table->getPaginationPageOptions();

        $this->assertNotContains('all', $options, 'Die "Alle"-Option muss entfernt sein.');
        $this->assertContains(500, $options);
        $this->assertContains(200, $options);
        $this->assertSame(500, max($options), 'Maximale Seitengröße muss 500 sein.');
    }

    public function test_remembered_large_page_size_is_clamped_to_200_on_reload(): void
    {
        $this->actingAs($this->admin());

        // Simulate a previously-chosen 500 persisted in the session.
        session()->put('tables.' . md5(ListTransactions::class) . '_per_page', 500);

        Livewire::test(ListTransactions::class)
            ->assertSet('tableRecordsPerPage', 200);
    }

    public function test_small_remembered_page_size_is_kept(): void
    {
        $this->actingAs($this->admin());

        session()->put('tables.' . md5(ListTransactions::class) . '_per_page', 100);

        Livewire::test(ListTransactions::class)
            ->assertSet('tableRecordsPerPage', 100);
    }
}
