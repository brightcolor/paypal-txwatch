<?php

namespace Tests\Feature\Audience;

use App\Filament\Pages\AudiencePage;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

/**
 * The page has to RENDER the figures, not only compute them.
 *
 * A smoke test that only asks for HTTP 200 would pass with an empty page; these
 * assertions name values that can only appear when the sections are really drawn.
 */
class AudienceViewTest extends TestCase
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

        Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'event_date' => '2026-07-10', 'is_active' => true]);
        Event::create(['name' => 'Winterball', 'pretix_event_slug' => 'winterball', 'event_date' => '2026-12-12', 'is_active' => true]);

        $this->ticketType('sommerfest', 3, 'VIP');
        $this->ticketType('winterball', 7, 'VIP');

        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3, 'price' => 40.0]], orderedAt: '2026-07-01 09:00:00');
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7, 'price' => 25.0]], orderedAt: '2026-11-01 09:00:00');
        $this->pretixOrder('winterball', 'W2', 'bodo@example.test', [['item' => 7, 'price' => 25.0, 'voucher' => '55']], orderedAt: '2026-11-02 09:00:00');
    }

    public function test_the_page_shows_every_section(): void
    {
        $antwort = $this->get(AudiencePage::getUrl());

        $antwort->assertSuccessful();

        foreach ([
            'Überschneidung der Veranstaltungen',
            'Neu und wiederkehrend je Veranstaltung',
            'Ticketarten',
            'Vorlaufzeit',
            'Gruppengröße',
            'Verkaufsverlauf',
            'Tage vor der Veranstaltung',
            'Bestellwert',
            'Gutscheine',
            'Zahlungsart',
            'Herkunft',
            'Storno und Ablauf',
        ] as $ueberschrift) {
            $antwort->assertSee($ueberschrift, false);
        }
    }

    public function test_the_matrix_carries_the_event_names_and_the_shared_share(): void
    {
        $antwort = $this->get(AudiencePage::getUrl());

        // anna ist die einzige Sommerfest-Kaeuferin und war auch beim Winterball: 100 %.
        // Von den zwei Winterball-Kaeufern war eine beim Sommerfest: 50 %.
        $antwort->assertSeeInOrder(['Sommerfest', 'Winterball', '100,0&nbsp;%', '50,0&nbsp;%'], false);
    }

    /**
     * The two day series are charts, and their numbers stay reachable.
     *
     * As tables they were 150 rows each - the shape of the curve disappeared in
     * them, and they made up a good part of the page weight.
     */
    public function test_the_day_series_are_drawn_as_charts_with_a_readable_fallback(): void
    {
        $antwort = $this->get(AudiencePage::getUrl());

        $antwort->assertSee('aud-vorlauf', false);
        $antwort->assertSee('aud-tage', false);
        $antwort->assertSee('x-ref="canvas"', false);
        $antwort->assertSee('Stärkste Verkaufstage', false);
        // Der bezahlte Bestelltag aus dem Aufbau, als lesbare Zahl daneben.
        $antwort->assertSee('01.07.2026', false);
    }

    public function test_the_origin_block_names_its_coverage(): void
    {
        $this->get(AudiencePage::getUrl())->assertSee('Abdeckung:', false);
    }

    public function test_a_voucher_identifier_appears_in_its_block(): void
    {
        $this->get(AudiencePage::getUrl())->assertSee('Gutschein-Kennung', false)->assertSee('55', false);
    }
}
