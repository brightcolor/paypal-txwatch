<?php

namespace Tests\Feature\Audience;

use App\Models\Customer;
use App\Models\Event;
use App\Models\User;
use App\Services\Audience\AudienceQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

/**
 * A promoter sees their own events and nothing else.
 *
 * THE COUNTER-TEST IS THE POINT: checking that their own event shows up proves
 * nothing about the scope - an unscoped query passes that just as well. The test
 * that matters is the foreign event that must be absent.
 */
class AudienceScopeTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    private Customer $eigener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->eigener = Customer::create(['name' => 'Verein Nord', 'is_active' => true]);
        $fremder = Customer::create(['name' => 'Verein Sued', 'is_active' => true]);

        Event::create(['customer_id' => $this->eigener->id, 'name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'is_active' => true]);
        Event::create(['customer_id' => $fremder->id, 'name' => 'Winterball', 'pretix_event_slug' => 'winterball', 'is_active' => true]);

        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3]]);
        $this->pretixOrder('winterball', 'BBBBB', 'b@example.test', [['item' => 7]]);
    }

    private function loginAs(string $role, ?int $customerId = null): void
    {
        $user = User::factory()->create(['customer_id' => $customerId]);
        $user->assignRole(Role::findByName($role));
        $this->actingAs($user);
    }

    public function test_an_admin_sees_every_event(): void
    {
        $this->loginAs('admin');

        $slugs = (new AudienceQuery())->positions()->pluck('event_slug')->unique()->sort()->values()->all();

        $this->assertSame(['sommerfest', 'winterball'], $slugs);
    }

    public function test_a_promoter_never_sees_a_foreign_event(): void
    {
        $this->loginAs('customer', $this->eigener->id);

        $slugs = (new AudienceQuery())->positions()->pluck('event_slug')->unique()->values()->all();

        $this->assertSame(['sommerfest'], $slugs);
    }

    public function test_a_promoter_asking_for_a_foreign_event_gets_nothing(): void
    {
        $this->loginAs('customer', $this->eigener->id);

        $this->assertSame(0, (new AudienceQuery(eventSlugs: ['winterball']))->positions()->count());
        $this->assertSame(0, (new AudienceQuery(eventSlugs: ['winterball']))->orders()->count());
    }

    public function test_a_promoter_without_a_customer_sees_nothing(): void
    {
        $this->loginAs('customer');

        $this->assertSame(0, (new AudienceQuery())->positions()->count());
        $this->assertSame(0, (new AudienceQuery())->visiblePositions()->count());
        $this->assertSame(0, (new AudienceQuery())->orders()->count());
    }

    public function test_the_status_filter_defaults_to_paid(): void
    {
        $this->loginAs('admin');
        $this->pretixOrder('sommerfest', 'CCCCC', 'c@example.test', [['item' => 3]], status: 'c');

        $this->assertSame(2, (new AudienceQuery())->positions()->count());
        $this->assertSame(3, (new AudienceQuery(statuses: []))->positions()->count());
    }

    public function test_the_date_range_includes_the_whole_last_day(): void
    {
        $this->loginAs('admin');
        $this->pretixOrder('sommerfest', 'SPAET', 'd@example.test', [['item' => 3]], orderedAt: '2026-07-06 23:30:00');

        $bisSechster = new AudienceQuery(
            from: \Illuminate\Support\Carbon::parse('2026-07-06'),
            until: \Illuminate\Support\Carbon::parse('2026-07-06'),
        );

        $this->assertSame(1, $bisSechster->positions()->count());
    }
}
