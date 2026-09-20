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

    public function test_the_origin_block_names_its_coverage(): void
    {
        $this->get(AudiencePage::getUrl())->assertSee('Abdeckung:', false);
    }

    public function test_a_voucher_identifier_appears_in_its_block(): void
    {
        $this->get(AudiencePage::getUrl())->assertSee('Gutschein-Kennung', false)->assertSee('55', false);
    }
}
