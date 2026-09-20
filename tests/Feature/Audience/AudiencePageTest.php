<?php

namespace Tests\Feature\Audience;

use App\Filament\Pages\AudiencePage;
use App\Models\Customer;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

/**
 * The page wires the selection form to the audience services.
 *
 * THE FILTER TESTS SET A VALUE AND CHECK A FIGURE: a smoke test sees the page draw,
 * but a form field that is not passed on to the query draws just as well.
 */
class AudiencePageTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'event_date' => '2026-07-10', 'is_active' => true]);
        Event::create(['name' => 'Winterball', 'pretix_event_slug' => 'winterball', 'event_date' => '2026-12-12', 'is_active' => true]);

        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3, 'price' => 40.0]]);
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7, 'price' => 25.0]]);
        $this->pretixOrder('winterball', 'W2', 'bodo@example.test', [['item' => 7, 'price' => 25.0]]);
    }

    private function loginAs(string $role, ?int $customerId = null): User
    {
        $user = User::factory()->create(['customer_id' => $customerId]);
        $user->assignRole(Role::findByName($role));
        $this->actingAs($user);

        return $user;
    }

    public function test_an_admin_can_open_the_page(): void
    {
        $this->loginAs('admin');

        $this->get(AudiencePage::getUrl())->assertSuccessful();
    }

    public function test_a_user_without_the_permission_is_kept_out(): void
    {
        $this->loginAs('auditor');

        $this->assertFalse(AudiencePage::canAccess());
        $this->get(AudiencePage::getUrl())->assertForbidden();
    }

    public function test_the_headline_figures_reach_the_page(): void
    {
        $this->loginAs('admin');

        $zahlen = Livewire::test(AudiencePage::class)->instance()->stats;

        $this->assertSame(2, $zahlen['buyers']);
        $this->assertSame(3, $zahlen['tickets']);
        $this->assertSame(1, $zahlen['multi_event_buyers']);
    }

    public function test_choosing_one_event_really_narrows_the_figures(): void
    {
        $this->loginAs('admin');

        $seite = Livewire::test(AudiencePage::class)
            ->set('data.event_slugs', ['winterball']);

        $zahlen = $seite->instance()->stats;

        $this->assertSame(2, $zahlen['buyers']);
        $this->assertSame(2, $zahlen['tickets']);
        $this->assertSame(0, $zahlen['multi_event_buyers']);
    }

    public function test_the_status_filter_really_applies(): void
    {
        $this->loginAs('admin');
        $this->pretixOrder('sommerfest', 'S9', 'clara@example.test', [['item' => 3]], status: 'c');

        $seite = Livewire::test(AudiencePage::class);
        $this->assertSame(3, $seite->instance()->stats['tickets']);

        $seite->set('data.statuses', ['p', 'c']);
        $this->assertSame(4, $seite->instance()->stats['tickets']);
    }

    public function test_the_date_range_really_applies(): void
    {
        $this->loginAs('admin');
        $this->pretixOrder('sommerfest', 'ALT', 'dora@example.test', [['item' => 3]], orderedAt: '2025-01-01 10:00:00');

        $seite = Livewire::test(AudiencePage::class);
        $this->assertSame(4, $seite->instance()->stats['tickets']);

        $seite->set('data.from', '2026-01-01');
        $this->assertSame(3, $seite->instance()->stats['tickets']);
    }

    public function test_a_promoter_sees_only_their_own_event(): void
    {
        $kunde = Customer::create(['name' => 'Verein Nord', 'is_active' => true]);
        Event::where('pretix_event_slug', 'sommerfest')->update(['customer_id' => $kunde->id]);

        $this->loginAs('customer', $kunde->id);

        $zahlen = Livewire::test(AudiencePage::class)->instance()->stats;

        $this->assertSame(1, $zahlen['tickets']);
    }

    public function test_an_event_without_orders_is_offered_with_a_hint(): void
    {
        Event::create(['name' => 'Fruehlingsfest', 'pretix_event_slug' => 'fruehling', 'is_active' => true]);

        $this->loginAs('admin');

        $seite = Livewire::test(AudiencePage::class);

        $this->assertContains('fruehling', $seite->get('data.event_slugs'));
        $seite->assertSee('Fruehlingsfest (noch keine Bestellungen)');
    }
}
