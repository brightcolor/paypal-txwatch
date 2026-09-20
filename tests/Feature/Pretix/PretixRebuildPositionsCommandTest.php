<?php

namespace Tests\Feature\Pretix;

use App\Models\PretixPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesPretixData;
use Tests\TestCase;

/**
 * The rebuild has to be usable on a stock that was imported before the table
 * existed - that is the only way the positions already in production get in.
 */
class PretixRebuildPositionsCommandTest extends TestCase
{
    use MakesPretixData;
    use RefreshDatabase;

    public function test_it_builds_the_rows_of_every_stored_order(): void
    {
        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3], ['item' => 4]], writePositions: false);
        $this->pretixOrder('winterball', 'BBBBB', 'b@example.test', [['item' => 7]], writePositions: false);

        $this->assertSame(0, PretixPosition::count());

        $this->artisan('pretix:rebuild-positions')
            ->expectsOutputToContain('2 Bestellungen gelesen, 3 Positionen geschrieben')
            ->assertSuccessful();

        $this->assertSame(3, PretixPosition::count());
    }

    public function test_it_can_be_limited_to_one_event(): void
    {
        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3]], writePositions: false);
        $this->pretixOrder('winterball', 'BBBBB', 'b@example.test', [['item' => 7]], writePositions: false);

        $this->artisan('pretix:rebuild-positions', ['--event' => 'winterball'])->assertSuccessful();

        $this->assertSame(1, PretixPosition::count());
        $this->assertSame('winterball', PretixPosition::first()->event_slug);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->pretixOrder('sommerfest', 'AAAAA', 'a@example.test', [['item' => 3], ['item' => 4]], writePositions: false);

        $this->artisan('pretix:rebuild-positions')->assertSuccessful();
        $this->artisan('pretix:rebuild-positions')->assertSuccessful();

        $this->assertSame(2, PretixPosition::count());
    }

    public function test_an_unknown_event_says_what_to_check(): void
    {
        $this->artisan('pretix:rebuild-positions', ['--event' => 'gibtsnicht'])
            ->expectsOutputToContain('Prüfe den Slug in der Eventverwaltung')
            ->assertSuccessful();
    }
}
