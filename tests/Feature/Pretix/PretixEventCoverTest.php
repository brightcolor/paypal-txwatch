<?php

namespace Tests\Feature\Pretix;

use App\Models\Event;
use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Services\Export\ExportDataBuilder;
use App\Services\Pretix\PretixEventCover;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PretixEventCoverTest extends TestCase
{
    use RefreshDatabase;

    private function fakePretix(): void
    {
        Http::fake([
            '*/orderpositions/*' => Http::response(['results' => [
                ['item' => 1, 'price' => '25.00', 'checkins' => [['list' => 1]]],
                ['item' => 1, 'price' => '25.00', 'checkins' => []],
                ['item' => 2, 'price' => '10.00', 'checkins' => [['list' => 1]]],
            ], 'next' => null]),
            '*/items/*' => Http::response(['results' => [
                ['id' => 1, 'name' => ['de' => 'Sitzplatz']],
                ['id' => 2, 'name' => ['de' => 'Stehplatz']],
            ], 'next' => null]),
            '*/quotas/*' => Http::response(['results' => [
                ['id' => 9, 'size' => 100, 'available_number' => 97],
            ], 'next' => null]),
            '*/events/sommerfest/' => Http::response([
                'name' => ['de' => 'Sommerfest'], 'location' => ['de' => 'Stadion Wismar'],
                'date_from' => '2026-08-01T15:00:00+02:00', 'date_admission' => '2026-08-01T13:30:00+02:00',
                'presale_start' => '2026-06-01T00:00:00+02:00', 'presale_end' => null,
                'currency' => 'EUR', 'live' => true,
            ]),
        ]);
    }

    private function eventWithConnection(): Event
    {
        $connection = PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein',
            'api_token' => 'tok', 'is_active' => true,
        ]);

        PretixOrder::create([
            'pretix_connection_id' => $connection->id, 'event_slug' => 'sommerfest', 'order_code' => 'AAAAA',
            'status' => 'p', 'url' => 'https://x/', 'raw_payload' => [],
        ]);

        return Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'is_active' => true]);
    }

    public function test_cover_aggregates_guest_ledger_per_category(): void
    {
        $this->fakePretix();
        $event = $this->eventWithConnection();

        $cover = app(PretixEventCover::class)->forEvent($event, fresh: true);

        $this->assertNotNull($cover);
        $this->assertSame('Stadion Wismar', $cover['details']['location']);

        $seats = collect($cover['categories'])->firstWhere('name', 'Sitzplatz');
        $this->assertSame(2, $seats['booked']);
        $this->assertSame(1, $seats['attended']);
        $this->assertSame(50.0, $seats['revenue']);

        $this->assertSame(3, $cover['totals']['booked']);
        $this->assertSame(2, $cover['totals']['attended']);
        $this->assertSame(1, $cover['totals']['no_shows']);
        $this->assertEqualsWithDelta(66.7, $cover['totals']['show_up_ratio'], 0.1);
        $this->assertSame(3, $cover['capacity']['sold']);
    }

    public function test_cover_is_null_without_slug_or_connection(): void
    {
        $noSlug = Event::create(['name' => 'Lokal', 'is_active' => true]);
        $this->assertNull(app(PretixEventCover::class)->forEvent($noSlug, fresh: true));

        $orphan = Event::create(['name' => 'X', 'pretix_event_slug' => 'unknown', 'is_active' => true]);
        $this->assertNull(app(PretixEventCover::class)->forEvent($orphan, fresh: true));
    }

    public function test_pdf_view_renders_cover_with_guest_ledger(): void
    {
        $this->fakePretix();
        $event = $this->eventWithConnection();

        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'event_id' => $event->id, 'transaction_id' => 'T1',
            'transaction_event_code' => 'T0006', 'gross_amount' => 25, 'currency' => 'EUR',
            'transaction_initiation_date' => now(),
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'a'), 'dedupe_key' => hash('sha256', 'b'), 'imported_at' => now(),
        ]);

        $built = app(ExportDataBuilder::class)->build(Transaction::query(), null, ['vat_rate' => 19.0]);
        $built['event'] = $event;
        $built['pretix_cover'] = app(PretixEventCover::class)->forEvent($event, fresh: true);

        $html = view('exports.pdf', $built)->render();

        $this->assertStringContainsString('Gästebilanz', $html);
        $this->assertStringContainsString('Sitzplatz', $html);
        $this->assertStringContainsString('Erscheinungsquote', $html);
        $this->assertStringContainsString('Stadion Wismar', $html);
        $this->assertStringContainsString('Einlass', $html);
    }

    public function test_pdf_view_renders_without_pretix_cover(): void
    {
        $event = Event::create(['name' => 'Plain', 'is_active' => true]);

        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'event_id' => $event->id, 'transaction_id' => 'T1',
            'transaction_event_code' => 'T0006', 'gross_amount' => 25, 'currency' => 'EUR',
            'transaction_initiation_date' => now(),
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'a'), 'dedupe_key' => hash('sha256', 'b'), 'imported_at' => now(),
        ]);

        $built = app(ExportDataBuilder::class)->build(Transaction::query(), null, ['vat_rate' => 19.0]);

        $html = view('exports.pdf', $built)->render();

        $this->assertStringNotContainsString('Gästebilanz', $html);
    }
}
