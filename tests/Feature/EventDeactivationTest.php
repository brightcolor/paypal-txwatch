<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\PaypalAccount;
use App\Models\PretixConnection;
use App\Models\Transaction;
use App\Services\Pretix\PretixOrderImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EventDeactivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_events_get_no_new_assignments_but_keep_existing_ones(): void
    {
        Http::fake([
            '*/events/sportfest/orders/*' => Http::response(['results' => [], 'next' => null]),
            '*/events/*' => Http::response(['results' => [['slug' => 'sportfest', 'name' => 'Sportfest']], 'next' => null]),
        ]);

        $connection = PretixConnection::create([
            'name' => 'V', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'v', 'api_token' => 'x',
        ]);
        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);

        $tx = fn (string $id) => Transaction::create([
            'paypal_account_id' => $account->id, 'transaction_id' => $id, 'transaction_event_code' => 'T0006',
            'custom_field' => 'Order SPORTFEST-' . $id, 'gross_amount' => 10, 'currency' => 'EUR',
            'raw_payload' => [], 'raw_hash' => hash('sha256', $id), 'dedupe_key' => hash('sha256', 'k' . $id), 'imported_at' => now(),
        ]);

        // First import: event created + assigned.
        $assigned = $tx('A1');
        app(PretixOrderImporter::class)->import($connection);
        $event = Event::where('pretix_event_slug', 'sportfest')->firstOrFail();
        $this->assertSame($event->id, $assigned->fresh()->event_id);

        // Deactivate, then a new transaction arrives and the import runs again:
        // no new assignment, the existing one stays, the import must not
        // silently re-activate the event.
        $event->update(['is_active' => false]);
        $unassigned = $tx('A2');
        app(PretixOrderImporter::class)->import($connection);

        $this->assertNull($unassigned->fresh()->event_id);
        $this->assertSame($event->id, $assigned->fresh()->event_id);
        $this->assertFalse($event->fresh()->is_active);
    }
}
