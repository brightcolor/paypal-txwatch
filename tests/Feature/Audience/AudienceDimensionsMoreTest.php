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

class AudienceDimensionsMoreTest extends TestCase
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

    public function test_the_sales_curve_counts_orders_per_day_weekday_and_time_of_day(): void
    {
        // 01.07.2026 ist ein Mittwoch, 04.07.2026 ein Samstag.
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3]], orderedAt: '2026-07-01 09:30:00');
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3]], orderedAt: '2026-07-01 21:00:00');
        $this->pretixOrder('sommerfest', 'S3', 'c@example.test', [['item' => 3]], orderedAt: '2026-07-04 13:00:00');

        $verlauf = app(AudienceDimensions::class)->salesCurve(new AudienceQuery());

        $this->assertSame(2, $verlauf['by_day']['2026-07-01']);
        $this->assertSame(1, $verlauf['by_day']['2026-07-04']);
        $this->assertSame(2, $verlauf['by_weekday']['Mittwoch']);
        $this->assertSame(1, $verlauf['by_weekday']['Samstag']);
        $this->assertSame(1, $verlauf['by_hour_block']['08–11 Uhr']);
        $this->assertSame(1, $verlauf['by_hour_block']['20–23 Uhr']);
    }

    public function test_the_second_axis_counts_days_before_the_event(): void
    {
        Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'event_date' => '2026-07-10', 'is_active' => true]);

        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3]], orderedAt: '2026-07-09 09:00:00');
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3]], orderedAt: '2026-07-09 18:00:00');
        $this->pretixOrder('sommerfest', 'S3', 'c@example.test', [['item' => 3]], orderedAt: '2026-06-10 09:00:00');
        // Ohne Event-Datensatz: zaehlt im Kalender mit, in der zweiten Achse nicht.
        $this->pretixOrder('winterball', 'W1', 'd@example.test', [['item' => 7]], orderedAt: '2026-07-01 09:00:00');

        $verlauf = app(AudienceDimensions::class)->salesCurve(new AudienceQuery());

        $this->assertSame(2, $verlauf['by_days_before'][1]);
        $this->assertSame(1, $verlauf['by_days_before'][30]);
        $this->assertSame(1, $verlauf['without_event_date']);
        $this->assertArrayNotHasKey(0, $verlauf['by_days_before']);
    }

    public function test_order_value_reports_median_and_average(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3, 'price' => 10.0]]);
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3, 'price' => 20.0]]);
        $this->pretixOrder('sommerfest', 'S3', 'c@example.test', [['item' => 3, 'price' => 120.0]]);

        $werte = app(AudienceDimensions::class)->orderValue(new AudienceQuery());

        $this->assertSame(20.0, $werte['median']);
        $this->assertSame(50.0, $werte['average']);
        $this->assertSame(1, $werte['classes']['über 100 €']);
    }

    public function test_the_median_of_an_even_count_is_the_middle_of_the_two(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3, 'price' => 10.0]]);
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3, 'price' => 30.0]]);

        $this->assertSame(20.0, app(AudienceDimensions::class)->orderValue(new AudienceQuery())['median']);
    }

    public function test_vouchers_are_counted_and_the_most_used_ones_listed(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3, 'voucher' => '55'], ['item' => 4]]);
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3, 'voucher' => '55']]);

        $gutscheine = app(AudienceDimensions::class)->vouchers(new AudienceQuery());

        $this->assertSame(2, $gutscheine['with']);
        $this->assertSame(3, $gutscheine['total']);
        $this->assertSame(66.7, $gutscheine['share']);
        $this->assertSame('55', $gutscheine['top'][0]['voucher']);
        $this->assertSame(2, $gutscheine['top'][0]['tickets']);
    }

    public function test_payment_providers_are_counted_per_order(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3], ['item' => 4]], provider: 'paypal');
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3]], provider: 'banktransfer');

        $arten = app(AudienceDimensions::class)->paymentProviders(new AudienceQuery());

        $this->assertSame(1, $arten['paypal']);
        $this->assertSame(1, $arten['banktransfer']);
    }

    public function test_origin_reports_regions_and_its_own_coverage(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3]], address: ['zipcode' => '23966', 'city' => 'Wismar', 'country' => 'DE']);
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3]], address: ['zipcode' => '23970', 'city' => 'Wismar', 'country' => 'DE']);
        $this->pretixOrder('sommerfest', 'S3', 'c@example.test', [['item' => 3]], address: []);

        $herkunft = app(AudienceDimensions::class)->origin(new AudienceQuery());

        $this->assertSame('23', $herkunft['regions'][0]['region']);
        $this->assertSame(2, $herkunft['regions'][0]['tickets']);
        $this->assertSame('Wismar', $herkunft['cities'][0]['city']);
        $this->assertSame(2, $herkunft['countries']['DE']);
        $this->assertSame(2, $herkunft['covered']);
        $this->assertSame(3, $herkunft['total']);
        $this->assertSame(66.7, $herkunft['coverage']);
    }

    public function test_cancellations_are_counted_although_the_status_filter_says_paid(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3]]);
        $this->pretixOrder('sommerfest', 'S2', 'b@example.test', [['item' => 3]], status: 'c');
        $this->pretixOrder('sommerfest', 'S3', 'c@example.test', [['item' => 3]], status: 'e');

        $storno = app(AudienceDimensions::class)->cancellations(new AudienceQuery());

        $this->assertSame(2, $storno['by_event']['sommerfest']['cancelled']);
        $this->assertSame(3, $storno['by_event']['sommerfest']['total']);
        $this->assertSame(66.7, $storno['by_event']['sommerfest']['share']);
        $this->assertSame(2, $storno['buyers_only_cancelled']);
    }

    public function test_a_buyer_with_a_paid_ticket_elsewhere_still_counts_as_only_cancelled_here(): void
    {
        $this->pretixOrder('sommerfest', 'S1', 'b@example.test', [['item' => 3]], status: 'c');
        $this->pretixOrder('winterball', 'W1', 'b@example.test', [['item' => 7]]);

        $storno = app(AudienceDimensions::class)->cancellations(new AudienceQuery(eventSlugs: ['sommerfest']));

        $this->assertSame(1, $storno['buyers_only_cancelled']);
    }

    public function test_position_questions_are_reported_generically(): void
    {
        $order = $this->pretixOrder('sommerfest', 'S1', 'a@example.test', [['item' => 3]]);
        $payload = $order->raw_payload;
        $payload['positions'][0]['answers'] = [[
            'question' => 17,
            'question_identifier' => 'SHUTTLE',
            'answer' => 'True',
        ]];
        $order->update(['raw_payload' => $payload]);

        $fragen = app(AudienceDimensions::class)->questions(new AudienceQuery());

        $this->assertSame('SHUTTLE', $fragen['SHUTTLE']['question']);
        $this->assertSame(1, $fragen['SHUTTLE']['answers']['True']);
    }
}
