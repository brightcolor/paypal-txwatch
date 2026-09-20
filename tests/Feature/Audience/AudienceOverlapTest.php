<?php

namespace Tests\Feature\Audience;

use App\Models\User;
use App\Services\Audience\AudienceOverlap;
use App\Services\Audience\AudienceQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

/**
 * Who of A was also at B.
 *
 * THE PERCENTAGE IS ASYMMETRIC on purpose: "3 of 4 buyers of A were also at B" and
 * "3 of 30 buyers of B were also at A" are different sentences, and only the first
 * one answers whether A's audience carries over.
 */
class AudienceOverlapTest extends TestCase
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

        // anna und bodo waren bei beiden, clara nur beim Sommerfest,
        // dora und emil nur beim Winterball.
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]], orderedAt: '2026-03-01 10:00:00');
        $this->pretixOrder('sommerfest', 'S2', 'bodo@example.test', [['item' => 3]], orderedAt: '2026-03-02 10:00:00');
        $this->pretixOrder('sommerfest', 'S3', 'clara@example.test', [['item' => 3]], orderedAt: '2026-03-03 10:00:00');
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7]], orderedAt: '2026-08-01 10:00:00');
        $this->pretixOrder('winterball', 'W2', 'bodo@example.test', [['item' => 7]], orderedAt: '2026-08-02 10:00:00');
        $this->pretixOrder('winterball', 'W3', 'dora@example.test', [['item' => 7]], orderedAt: '2026-08-03 10:00:00');
        $this->pretixOrder('winterball', 'W4', 'emil@example.test', [['item' => 7]], orderedAt: '2026-08-04 10:00:00');
    }

    public function test_the_matrix_counts_shared_buyers_per_pair(): void
    {
        $matrix = app(AudienceOverlap::class)->matrix(new AudienceQuery());

        $this->assertSame(['sommerfest', 'winterball'], $matrix['events']);
        $this->assertSame(3, $matrix['rows']['sommerfest']['buyers']);
        $this->assertSame(4, $matrix['rows']['winterball']['buyers']);
        $this->assertSame(2, $matrix['rows']['sommerfest']['shared']['winterball']['count']);
        $this->assertSame(2, $matrix['rows']['winterball']['shared']['sommerfest']['count']);
    }

    public function test_the_share_is_measured_against_the_row_event(): void
    {
        $matrix = app(AudienceOverlap::class)->matrix(new AudienceQuery());

        $this->assertSame(66.7, $matrix['rows']['sommerfest']['shared']['winterball']['share']);
        $this->assertSame(50.0, $matrix['rows']['winterball']['shared']['sommerfest']['share']);
    }

    public function test_an_event_shares_all_of_its_buyers_with_itself(): void
    {
        $matrix = app(AudienceOverlap::class)->matrix(new AudienceQuery());

        $this->assertSame(3, $matrix['rows']['sommerfest']['shared']['sommerfest']['count']);
        $this->assertSame(100.0, $matrix['rows']['sommerfest']['shared']['sommerfest']['share']);
    }

    public function test_first_time_buyers_are_measured_against_the_whole_visible_stock(): void
    {
        $zahlen = app(AudienceOverlap::class)->firstTimeByEvent(new AudienceQuery());

        // Alle drei Sommerfest-Kaeufer kaufen dort zuerst.
        $this->assertSame(3, $zahlen['sommerfest']['first_time']);
        $this->assertSame(0, $zahlen['sommerfest']['returning']);

        // Beim Winterball sind anna und bodo wiederkehrend, dora und emil neu.
        $this->assertSame(2, $zahlen['winterball']['first_time']);
        $this->assertSame(2, $zahlen['winterball']['returning']);
        $this->assertSame(50.0, $zahlen['winterball']['share']);
    }

    public function test_narrowing_the_selection_leaves_the_first_time_verdict_alone(): void
    {
        $zahlen = app(AudienceOverlap::class)->firstTimeByEvent(new AudienceQuery(eventSlugs: ['winterball']));

        // anna und bodo bleiben wiederkehrend, obwohl das Sommerfest nicht gewaehlt ist.
        $this->assertSame(2, $zahlen['winterball']['returning']);
        $this->assertArrayNotHasKey('sommerfest', $zahlen);
    }

    public function test_a_tie_goes_to_the_alphabetically_first_event(): void
    {
        $this->pretixOrder('aaa-fest', 'T1', 'fritz@example.test', [['item' => 1]], orderedAt: '2026-09-01 10:00:00');
        $this->pretixOrder('zzz-fest', 'T2', 'fritz@example.test', [['item' => 1]], orderedAt: '2026-09-01 10:00:00');

        $zahlen = app(AudienceOverlap::class)->firstTimeByEvent(new AudienceQuery(eventSlugs: ['aaa-fest', 'zzz-fest']));

        $this->assertSame(1, $zahlen['aaa-fest']['first_time']);
        $this->assertSame(1, $zahlen['zzz-fest']['returning']);
    }
}
