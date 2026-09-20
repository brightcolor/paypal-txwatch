<?php

namespace Tests\Feature\Audience;

use App\Models\User;
use App\Services\Audience\AudienceQuery;
use App\Services\Audience\AudienceStats;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

class AudienceStatsTest extends TestCase
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

    public function test_it_counts_buyers_tickets_orders_and_revenue(): void
    {
        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3, 'price' => 40.0], ['item' => 4, 'price' => 10.0]]);
        $this->pretixOrder('sommerfest', 'BBBBB', 'b@example.test', [['item' => 3, 'price' => 40.0]]);
        $this->pretixOrder('winterball', 'CCCCC', 'A@Example.test', [['item' => 7, 'price' => 25.0]]);

        $zahlen = app(AudienceStats::class)->forSelection(new AudienceQuery());

        $this->assertSame(2, $zahlen['buyers']);
        $this->assertSame(4, $zahlen['tickets']);
        $this->assertSame(3, $zahlen['orders']);
        $this->assertSame(115.0, $zahlen['revenue']);
    }

    public function test_tickets_without_a_buyer_count_as_tickets_and_not_as_people(): void
    {
        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3]]);
        $this->pretixOrder('sommerfest', 'LEER1', null, [['item' => 3]]);

        $zahlen = app(AudienceStats::class)->forSelection(new AudienceQuery());

        $this->assertSame(1, $zahlen['buyers']);
        $this->assertSame(2, $zahlen['tickets']);
        $this->assertSame(1, $zahlen['tickets_without_buyer']);
    }

    public function test_it_counts_buyers_who_appear_at_more_than_one_event(): void
    {
        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3]]);
        $this->pretixOrder('winterball', 'BBBBB', 'a@example.test', [['item' => 7]]);
        $this->pretixOrder('winterball', 'CCCCC', 'b@example.test', [['item' => 7]]);

        $zahlen = app(AudienceStats::class)->forSelection(new AudienceQuery());

        $this->assertSame(1, $zahlen['multi_event_buyers']);
        $this->assertSame(50.0, $zahlen['returning_share']);
    }

    public function test_the_same_order_code_at_two_events_counts_twice(): void
    {
        $this->pretixOrder('sommerfest', 'GLEICH', 'a@example.test', [['item' => 3]]);
        $this->pretixOrder('winterball', 'GLEICH', 'b@example.test', [['item' => 7]]);

        $this->assertSame(2, app(AudienceStats::class)->forSelection(new AudienceQuery())['orders']);
    }

    public function test_an_empty_selection_gives_zeros_and_no_division_error(): void
    {
        $zahlen = app(AudienceStats::class)->forSelection(new AudienceQuery());

        $this->assertSame(0, $zahlen['buyers']);
        $this->assertSame(0, $zahlen['orders']);
        $this->assertSame(0.0, $zahlen['revenue']);
        $this->assertSame(0.0, $zahlen['returning_share']);
    }
}
