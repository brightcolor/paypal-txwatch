<?php

namespace Tests\Feature\Audience;

use App\Filament\Pages\AudiencePage;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

class AudienceBuyerTableTest extends TestCase
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
        $this->pretixOrder('winterball', 'W2', 'bodo@example.test', [['item' => 7, 'price' => 25.0]], address: ['name' => 'Bodo Klein']);
    }

    public function test_the_table_lists_one_row_per_buyer(): void
    {
        $seite = Livewire::test(AudiencePage::class);

        $seite->assertSee('anna@example.test')->assertSee('bodo@example.test');

        // Anna hat zwei Bestellungen an zwei Veranstaltungen und trotzdem eine Zeile.
        $this->assertCount(2, $seite->instance()->getTableRecords());
    }

    public function test_it_can_be_sorted_by_every_sortable_column(): void
    {
        $seite = Livewire::test(AudiencePage::class);

        foreach (['events', 'orders', 'tickets', 'revenue', 'first_at', 'last_at'] as $spalte) {
            $seite->sortTable($spalte, 'desc')->assertSuccessful();
        }
    }

    public function test_sorting_by_tickets_puts_the_biggest_buyer_first(): void
    {
        $zeilen = Livewire::test(AudiencePage::class)
            ->sortTable('tickets', 'desc')
            ->instance()
            ->getTableRecords();

        $this->assertSame('anna@example.test', $zeilen->first()->buyer_email);
    }

    public function test_searching_is_case_insensitive(): void
    {
        Livewire::test(AudiencePage::class)
            ->searchTable('ANNA')
            ->assertSee('anna@example.test')
            ->assertDontSee('bodo@example.test');
    }

    public function test_narrowing_the_events_narrows_the_table(): void
    {
        Livewire::test(AudiencePage::class)
            ->set('data.event_slugs', ['sommerfest'])
            ->assertSee('anna@example.test')
            ->assertDontSee('bodo@example.test');
    }

    /**
     * No ORDER BY on a column outside the GROUP BY.
     *
     * Filament appends the primary key as a last sort for stable pages. On this
     * grouped query that is `pretix_positions.id`, which is neither grouped nor
     * aggregated: SQLite lets it through, PostgreSQL refuses the whole query. So
     * this reads the SQL instead of trusting a green SQLite run.
     */
    public function test_the_sort_stays_valid_for_postgres_on_the_grouped_query(): void
    {
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $seite = Livewire::test(AudiencePage::class);

        foreach (['tickets', 'revenue', 'first_at'] as $spalte) {
            $seite->sortTable($spalte, 'desc');
        }

        $gruppiert = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => str_contains(strtolower($sql), 'group by "buyer_email"'))
            ->values();

        $this->assertNotEmpty($gruppiert, 'Die Kaeuferliste hat keine gruppierte Abfrage erzeugt.');

        foreach ($gruppiert as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/order by .*"pretix_positions"\."id"/i',
                $sql,
                'Die Käuferliste sortiert nach einer Spalte außerhalb des GROUP BY – PostgreSQL lehnt das ab.',
            );
        }
    }

    public function test_the_table_offers_no_page_size_above_two_hundred(): void
    {
        $optionen = Livewire::test(AudiencePage::class)->instance()->getTable()->getPaginationPageOptions();

        $this->assertSame([25, 50, 100, 200], $optionen);
    }
}
