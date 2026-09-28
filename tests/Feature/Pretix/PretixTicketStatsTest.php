<?php

namespace Tests\Feature\Pretix;

use App\Models\PretixConnection;
use App\Services\Pretix\PretixClient;
use App\Services\Pretix\PretixTicketStats;
use App\Services\Pretix\PretixUnavailable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('failures')]
    public function test_a_failed_call_names_its_reason_and_caches_nothing(mixed $fake, string $reason, ?int $status): void
    {
        Http::fake(['*' => $fake]);
        $connection = $this->connection();

        try {
            app(PretixTicketStats::class)->forConnection($connection);
            $this->fail('Der Fehlschlag wurde nicht gemeldet.');
        } catch (PretixUnavailable $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($status, $e->status);
            $this->assertStringStartsWith('pretix-Verbindung „Verein“: ', $e->userMessage());
        }

        $this->assertFalse(Cache::has("pretix_ticket_stats:{$connection->id}"));
    }

    /**
     * The facade is not available before the application boots, so the
     * provider builds the fakes on the factory directly.
     *
     * @return array<string, array{0: mixed, 1: string, 2: ?int}>
     */
    public static function failures(): array
    {
        return [
            'nicht erreichbar' => [Factory::failedConnection(), PretixUnavailable::UNREACHABLE, null],
            'Zeitüberschreitung' => [
                fn (Request $request) => Create::rejectionFor(new ConnectException(
                    'cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received',
                    $request->toPsrRequest(), null, ['errno' => 28],
                )),
                PretixUnavailable::TIMEOUT, null,
            ],
            'Token ungültig' => [Factory::response(['detail' => 'Invalid token.'], 401), PretixUnavailable::DENIED, 401],
            'Token ohne Recht' => [Factory::response(['detail' => 'Permission denied.'], 403), PretixUnavailable::DENIED, 403],
            'Veranstalter unbekannt' => [Factory::response(['detail' => 'Not found.'], 404), PretixUnavailable::NOT_FOUND, 404],
            'zu viele Anfragen' => [Factory::response(['detail' => 'Throttled.'], 429), PretixUnavailable::RATE_LIMITED, 429],
            'andere Abweisung' => [Factory::response(['detail' => 'Bad request.'], 400), PretixUnavailable::REJECTED, 400],
            'Serverfehler' => [Factory::response('Bad Gateway', 502), PretixUnavailable::SERVER_ERROR, 502],
        ];
    }

    public function test_the_log_message_carries_the_cause_but_never_the_token(): void
    {
        // A response that echoes the token must not carry it into the error log.
        Http::fake(['*' => Http::response(['detail' => 'Token tok-4711-geheim is not valid.'], 401)]);
        $connection = $this->connection('tok-4711-geheim');

        try {
            app(PretixTicketStats::class)->forConnection($connection);
            $this->fail('Der Fehlschlag wurde nicht gemeldet.');
        } catch (PretixUnavailable $e) {
            $this->assertStringContainsString("(ID {$connection->id})", $e->getMessage());
            $this->assertStringContainsString('status code 401', $e->getMessage());
            $this->assertStringNotContainsString('tok-4711-geheim', $e->getMessage());
            $this->assertStringNotContainsString('tok-4711-geheim', $e->userMessage());
        }
    }

    public function test_the_figures_stay_cached_for_the_configured_seconds(): void
    {
        config(['pretix.ticket_stats_cache_seconds' => 120]);
        $this->fakeFigures();
        $connection = $this->connection();

        app(PretixTicketStats::class)->forConnection($connection);
        $this->travel(119)->seconds();
        app(PretixTicketStats::class)->forConnection($connection);
        Http::assertSentCount(2); // events + quotas, once

        $this->travel(2)->seconds();
        app(PretixTicketStats::class)->forConnection($connection);
        Http::assertSentCount(4);
    }

    public function test_zero_seconds_turns_the_cache_off(): void
    {
        config(['pretix.ticket_stats_cache_seconds' => 0]);
        $this->fakeFigures();
        $connection = $this->connection();

        app(PretixTicketStats::class)->forConnection($connection);
        app(PretixTicketStats::class)->forConnection($connection);

        Http::assertSentCount(4);
        $this->assertFalse(Cache::has("pretix_ticket_stats:{$connection->id}"));
    }

    public function test_every_pretix_call_uses_the_configured_timeout(): void
    {
        config(['pretix.http_timeout' => 7]);
        $timeouts = [];
        Http::fake(function (Request $request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;

            return str_contains($request->url(), '/quotas/')
                ? Http::response(['results' => [['id' => 1, 'size' => 10, 'available_number' => 4]], 'next' => null])
                : Http::response(['results' => [['slug' => 'gala', 'name' => 'Gala']], 'next' => null]);
        });

        app(PretixTicketStats::class)->forConnection($this->connection());

        $this->assertSame([7, 7], $timeouts);
    }

    public function test_the_event_cover_still_gets_neutral_figures_when_quotas_fail(): void
    {
        Http::fake(['*' => Http::response('Bad Gateway', 502)]);

        $availability = (new PretixClient($this->connection()))->ticketAvailability('gala');

        $this->assertSame(['capacity' => null, 'available' => 0, 'sold' => 0, 'unlimited' => false, 'quotas' => 0], $availability);
    }

    private function connection(string $token = 't'): PretixConnection
    {
        return PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.example.test', 'organizer_slug' => 'verein',
            'api_token' => $token, 'is_active' => true,
        ]);
    }

    private function fakeFigures(): void
    {
        Http::fake([
            '*/quotas/*' => Http::response(['results' => [['id' => 1, 'size' => 10, 'available_number' => 4]], 'next' => null]),
            '*/events/*' => Http::response(['results' => [['slug' => 'gala', 'name' => 'Gala']], 'next' => null]),
        ]);
    }
}
