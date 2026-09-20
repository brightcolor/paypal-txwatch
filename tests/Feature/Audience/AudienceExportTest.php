<?php

namespace Tests\Feature\Audience;

use App\Exports\AudienceBuyersExport;
use App\Exports\AudienceOverlapExport;
use App\Filament\Pages\AudiencePage;
use App\Models\Event;
use App\Models\User;
use App\Services\Audience\AudienceQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

class AudienceExportTest extends TestCase
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

        Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'is_active' => true]);
        Event::create(['name' => 'Winterball', 'pretix_event_slug' => 'winterball', 'is_active' => true]);

        $this->pretixOrder('sommerfest', 'S1', 'anna@example.test', [['item' => 3, 'price' => 40.0]], address: ['name' => 'Anna Beispiel']);
        $this->pretixOrder('winterball', 'W1', 'anna@example.test', [['item' => 7, 'price' => 25.0]], address: ['name' => 'Anna Beispiel']);
    }

    public function test_the_buyer_export_has_a_heading_row_and_one_row_per_buyer(): void
    {
        $export = new AudienceBuyersExport(new AudienceQuery());

        $this->assertSame(
            ['E-Mail', 'Name', 'Veranstaltungen', 'Bestellungen', 'Tickets', 'Umsatz', 'Erste Bestellung', 'Letzte Bestellung'],
            $export->headings(),
        );

        $zeilen = $export->array();

        $this->assertCount(1, $zeilen);
        $this->assertSame('anna@example.test', $zeilen[0][0]);
        $this->assertSame('Anna Beispiel', $zeilen[0][1]);
        $this->assertSame('Sommerfest, Winterball', $zeilen[0][2]);
        $this->assertSame(2, $zeilen[0][4]);
        $this->assertSame(65.0, $zeilen[0][5]);
    }

    public function test_the_overlap_export_is_a_square_of_events(): void
    {
        $export = new AudienceOverlapExport(new AudienceQuery());

        $this->assertSame(['Veranstaltung', 'Käufer', 'Sommerfest', 'Winterball'], $export->headings());

        $zeilen = $export->array();

        $this->assertCount(2, $zeilen);
        $this->assertSame(['Sommerfest', 1, 1, 1], $zeilen[0]);
    }

    public function test_the_page_hands_out_files(): void
    {
        $seite = Livewire::test(AudiencePage::class)->instance();

        $this->assertInstanceOf(StreamedResponse::class, $seite->downloadBuyers('csv'));
        $this->assertInstanceOf(StreamedResponse::class, $seite->downloadBuyers('xlsx'));
        $this->assertInstanceOf(StreamedResponse::class, $seite->downloadOverlap());
    }

    public function test_the_csv_contains_the_heading_and_the_buyer(): void
    {
        $antwort = Livewire::test(AudiencePage::class)->instance()->downloadBuyers('csv');

        ob_start();
        $antwort->sendContent();
        $inhalt = (string) ob_get_clean();

        $this->assertStringContainsString('E-Mail', $inhalt);
        $this->assertStringContainsString('anna@example.test', $inhalt);
    }

    public function test_the_download_respects_the_current_selection(): void
    {
        $export = new AudienceBuyersExport(new AudienceQuery(eventSlugs: ['sommerfest']));

        $this->assertSame('Sommerfest', $export->array()[0][2]);
    }

    public function test_an_empty_selection_is_refused_with_a_reason(): void
    {
        $seite = Livewire::test(AudiencePage::class)->set('data.event_slugs', ['gibtsnicht']);

        $this->assertNull($seite->instance()->downloadBuyers('csv'));
        $seite->assertNotified('Nichts zu exportieren');
    }

    public function test_no_file_with_personal_data_is_left_on_disk(): void
    {
        Storage::fake('local');

        Livewire::test(AudiencePage::class)->instance()->downloadBuyers('xlsx');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
