<?php

namespace Tests\Feature\Audience;

use App\Models\Event;
use App\Models\User;
use App\Services\Audience\AudienceBuyers;
use App\Services\Audience\AudienceQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

class AudienceBuyersTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);
    }

    public function test_one_row_per_buyer_with_their_totals(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [
            ['item' => 3, 'price' => 40.0],
            ['item' => 4, 'price' => 10.0],
        ], orderedAt: '2026-03-01 10:00:00');

        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [
            ['item' => 7, 'price' => 25.0],
        ], orderedAt: '2026-08-01 10:00:00');

        $this->pretixOrder('winterball', 'W2', 'bodo@example.test', [['item' => 7, 'price' => 25.0]]);

        $zeilen = app(AudienceBuyers::class)->query(new AudienceQuery())->get()->keyBy('buyer_email');

        $this->assertCount(2, $zeilen);

        $anna = $zeilen['anna@example.test'];
        $this->assertSame(3, (int) $anna->tickets);
        $this->assertSame(2, (int) $anna->orders);
        $this->assertSame(2, (int) $anna->events);
        $this->assertSame(75.0, (float) $anna->revenue);
        $this->assertStringStartsWith('2026-03-01', (string) $anna->first_at);
        $this->assertStringStartsWith('2026-08-01', (string) $anna->last_at);
    }

    public function test_a_buyer_without_an_address_is_left_out(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]]);
        $this->pretixOrder('sommerfest', 'LEER1', null, [['item' => 3]]);

        $this->assertCount(1, app(AudienceBuyers::class)->query(new AudienceQuery())->get());
    }

    public function test_the_display_name_is_one_of_the_names_on_that_address(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]], address: ['name' => 'Anna Beispiel']);

        $zeile = app(AudienceBuyers::class)->query(new AudienceQuery())->first();

        $this->assertSame('Anna Beispiel', $zeile->buyer_display_name);
    }

    public function test_the_event_names_of_one_buyer_come_back_readable(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]]);
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7]]);

        Event::create(['name' => 'Sommerfest 2026', 'pretix_event_slug' => 'sommerfest', 'is_active' => true]);

        $namen = app(AudienceBuyers::class)->eventNames(new AudienceQuery(), 'anna@example.test');

        // Der Slug ohne Event-Datensatz bleibt als Slug stehen, statt zu verschwinden.
        $this->assertSame(['Sommerfest 2026', 'winterball'], $namen);
    }

    public function test_the_list_can_be_searched_case_insensitively(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'Anna.Gross@Example.test', [['item' => 3]], address: ['name' => 'Anna Gross']);
        $this->pretixOrder('sommerfest', 'S2', 'bodo@example.test', [['item' => 3]], address: ['name' => 'Bodo Klein']);

        $treffer = AudienceBuyers::search(
            app(AudienceBuyers::class)->query(new AudienceQuery()),
            'ANNA',
        )->get();

        $this->assertCount(1, $treffer);
        $this->assertSame('anna.gross@example.test', $treffer->first()->buyer_email);
    }
}
