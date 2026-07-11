<?php

namespace Tests\Feature\Pretix;

use App\Models\PretixConnection;
use App\Services\Pretix\PretixTicketStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PretixTicketStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregates_capacity_and_sold_from_quotas(): void
    {
        Http::fake([
            // Quotas first (more specific) so it wins over the events pattern.
            '*/quotas/*' => Http::response([
                'results' => [
                    ['id' => 1, 'name' => 'Stehplatz', 'size' => 100, 'available_number' => 40],
                    ['id' => 2, 'name' => 'Sitzplatz', 'size' => 50, 'available_number' => 10],
                ],
                'next' => null,
            ]),
            '*/events/*' => Http::response([
                'results' => [['slug' => 'sommerfest', 'name' => ['de' => 'Sommerfest']]],
                'next' => null,
            ]),
        ]);

        $connection = PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein',
            'api_token' => 'tok', 'is_active' => true,
        ]);

        $rows = app(PretixTicketStats::class)->forConnection($connection, fresh: true);

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('Sommerfest', $row['name']);
        $this->assertSame(150, $row['capacity']);   // 100 + 50
        $this->assertSame(50, $row['available']);    // 40 + 10
        $this->assertSame(100, $row['sold']);        // 150 - 50
        $this->assertEqualsWithDelta(66.7, $row['ratio'], 0.1);
    }

    public function test_unlimited_quota_marks_event_uncapped(): void
    {
        Http::fake([
            '*/quotas/*' => Http::response([
                'results' => [['id' => 1, 'name' => 'Frei', 'size' => null, 'available_number' => null]],
                'next' => null,
            ]),
            '*/events/*' => Http::response(['results' => [['slug' => 'gala', 'name' => 'Gala']], 'next' => null]),
        ]);

        $connection = PretixConnection::create([
            'name' => 'V', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'v', 'api_token' => 't', 'is_active' => true,
        ]);

        $row = app(PretixTicketStats::class)->forConnection($connection, fresh: true)->first();

        $this->assertTrue($row['unlimited']);
        $this->assertNull($row['capacity']);
        $this->assertNull($row['ratio']);
    }
}
