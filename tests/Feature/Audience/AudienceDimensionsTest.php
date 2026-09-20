<?php

namespace Tests\Feature\Audience;

use App\Models\Event;
use App\Models\User;
use App\Services\Audience\AudienceDimensions;
use App\Services\Audience\AudienceQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

class AudienceDimensionsTest extends TestCase
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

        $this->ticketType('sommerfest', 3, 'VIP');
        $this->ticketType('sommerfest', 4, 'Normal');
        $this->ticketType('winterball', 7, 'VIP');
    }

    public function test_ticket_types_are_counted_per_event_with_their_names(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [
            ['item' => 3, 'price' => 40.0],
            ['item' => 4, 'price' => 10.0],
            ['item' => 4, 'price' => 10.0],
        ]);

        $arten = app(AudienceDimensions::class)->ticketTypes(new AudienceQuery());

        $this->assertSame('Normal', $arten[0]['name']);
        $this->assertSame(2, $arten[0]['tickets']);
        $this->assertSame(20.0, $arten[0]['revenue']);
        $this->assertSame('VIP', $arten[1]['name']);
        $this->assertSame(1, $arten[1]['tickets']);
    }

    public function test_an_unknown_ticket_type_keeps_its_number(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 99]]);

        $arten = app(AudienceDimensions::class)->ticketTypes(new AudienceQuery());

        $this->assertSame('Ticketart #99', $arten[0]['name']);
    }

    public function test_loyalty_to_a_ticket_type_compares_names_and_not_numbers(): void
    {
        // anna kauft bei beiden Events "VIP" - unter verschiedenen Nummern.
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]]);
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7]]);

        // bodo wechselt von Normal zu VIP.
        $this->pretixOrder('sommerfest', 'S2', 'bodo@example.test', [['item' => 4]]);
        $this->pretixOrder('winterball', 'W2', 'bodo@example.test', [['item' => 7]]);

        $treue = app(AudienceDimensions::class)->sameTypeAcrossEvents(new AudienceQuery());

        $this->assertSame(2, $treue['buyers']);
        $this->assertSame(1, $treue['loyal']);
        $this->assertSame(50.0, $treue['share']);
    }

    public function test_lead_time_uses_the_event_date_and_reports_its_coverage(): void
    {
        Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'event_date' => '2026-07-10', 'is_active' => true]);

        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]], orderedAt: '2026-07-10 08:00:00');
        $this->pretixOrder('sommerfest', 'S2', 'bodo@example.test', [['item' => 3]], orderedAt: '2026-07-08 08:00:00');
        $this->pretixOrder('sommerfest', 'S3', 'clara@example.test', [['item' => 3]], orderedAt: '2026-01-10 08:00:00');
        // Ohne Event-Datensatz, faellt aus der Quote heraus.
        $this->pretixOrder('winterball', 'W1', 'dora@example.test', [['item' => 7]], orderedAt: '2026-07-01 08:00:00');

        $vorlauf = app(AudienceDimensions::class)->leadTime(new AudienceQuery());

        $this->assertSame(1, $vorlauf['classes']['am Tag selbst']);
        $this->assertSame(1, $vorlauf['classes']['1 bis 3 Tage']);
        $this->assertSame(1, $vorlauf['classes']['über 90 Tage']);
        $this->assertSame(3, $vorlauf['covered']);
        $this->assertSame(1, $vorlauf['uncovered']);
    }

    public function test_group_size_counts_tickets_per_order(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3]]);
        $this->pretixOrder('sommerfest', 'S2', 'bodo@example.test', [['item' => 3], ['item' => 4]]);
        $this->pretixOrder('sommerfest', 'S3', 'clara@example.test', [['item' => 3], ['item' => 4], ['item' => 4]]);

        $gruppen = app(AudienceDimensions::class)->groupSize(new AudienceQuery());

        $this->assertSame(1, $gruppen['classes']['1']);
        $this->assertSame(1, $gruppen['classes']['2']);
        $this->assertSame(1, $gruppen['classes']['3']);
        $this->assertSame(2.0, $gruppen['average']);
    }

    public function test_a_differing_name_on_the_ticket_counts_as_company(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [
            ['item' => 3, 'attendee' => 'Anna Beispiel'],
            ['item' => 4, 'attendee' => 'Lena Anders'],
        ], address: ['name' => 'Anna Beispiel']);

        $gruppen = app(AudienceDimensions::class)->groupSize(new AudienceQuery());

        $this->assertSame(2, $gruppen['comparable']);
        $this->assertSame(1, $gruppen['with_other_attendee']);
    }
}
