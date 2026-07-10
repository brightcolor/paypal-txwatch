<?php

namespace Tests\Feature\Pretix;

use App\Models\PaypalAccount;
use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Models\Transaction;
use App\Services\Pretix\PretixOrderImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PretixImportReconcileTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): PretixConnection
    {
        return PretixConnection::create([
            'name' => 'Verein',
            'base_url' => 'https://pretix.eu',
            'organizer_slug' => 'verein',
            'api_token' => 'tok',
        ]);
    }

    private function tx(string $customField, float $gross): Transaction
    {
        static $i = 0;
        $i++;
        $account = PaypalAccount::firstOrCreate(['name' => 'Acc'], ['mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);

        return Transaction::create([
            'paypal_account_id' => $account->id,
            'transaction_id' => 'TXN' . $i,
            'transaction_event_code' => 'T0006',
            'custom_field' => $customField,
            'gross_amount' => $gross,
            'currency' => 'EUR',
            'raw_payload' => [],
            'raw_hash' => hash('sha256', 'h' . $i),
            'dedupe_key' => hash('sha256', 'k' . $i),
            'imported_at' => now(),
        ]);
    }

    private function fakePretix(): void
    {
        Http::fake([
            '*/events/sportfest/orders/*' => Http::response([
                'results' => [
                    ['code' => 'ABCDE', 'status' => 'p', 'total' => '50.00', 'currency' => 'EUR', 'email' => 'a@x.de', 'payments' => [['provider' => 'paypal']]],
                    ['code' => 'FGHIJ', 'status' => 'p', 'total' => '99.00', 'currency' => 'EUR', 'email' => 'b@x.de', 'payments' => [['provider' => 'banktransfer']]],
                ],
                'next' => null,
            ]),
            '*/events/*' => Http::response([
                'results' => [['slug' => 'sportfest', 'name' => 'Sportfest']],
                'next' => null,
            ]),
        ]);
    }

    public function test_import_stores_orders_and_reconciles_against_paypal(): void
    {
        $this->fakePretix();
        $connection = $this->connection();

        // pretix slug is lower-case, PayPal custom field is upper - matching is case-insensitive.
        $matched = $this->tx('Order SPORTFEST-ABCDE', 50.00);
        $mismatch = $this->tx('Order SPORTFEST-FGHIJ', 30.00);   // pretix says 99
        $unmatched = $this->tx('Order SPORTFEST-ZZZZZ', 20.00);  // no such pretix order

        $summary = app(PretixOrderImporter::class)->import($connection);

        $this->assertSame(1, $summary['events']);
        $this->assertSame(2, $summary['orders']);
        $this->assertSame(2, PretixOrder::count());

        $this->assertSame(Transaction::RECONCILIATION_MATCHED, $matched->fresh()->reconciliation_status);
        $this->assertNotNull($matched->fresh()->pretix_order_id);
        $this->assertStringContainsString('/control/event/verein/sportfest/orders/ABCDE/', $matched->fresh()->pretixOrderUrl());

        $this->assertSame(Transaction::RECONCILIATION_MISMATCH, $mismatch->fresh()->reconciliation_status);
        $this->assertSame(Transaction::RECONCILIATION_UNMATCHED, $unmatched->fresh()->reconciliation_status);
        $this->assertNull($unmatched->fresh()->pretix_order_id);

        $this->assertSame(1, $summary['matched']);
        $this->assertSame(1, $summary['mismatch']);
        $this->assertSame(1, $summary['unmatched']);
    }

    public function test_reimport_is_idempotent(): void
    {
        $this->fakePretix();
        $connection = $this->connection();
        $this->tx('Order SPORTFEST-ABCDE', 50.00);

        app(PretixOrderImporter::class)->import($connection);
        app(PretixOrderImporter::class)->import($connection);

        $this->assertSame(2, PretixOrder::count()); // not 4
    }

    public function test_payment_provider_is_extracted_from_nested_payments(): void
    {
        $this->fakePretix();
        $connection = $this->connection();

        app(PretixOrderImporter::class)->import($connection);

        $this->assertTrue(PretixOrder::where('order_code', 'ABCDE')->first()->isPaypal());
        $this->assertFalse(PretixOrder::where('order_code', 'FGHIJ')->first()->isPaypal());
    }
}
