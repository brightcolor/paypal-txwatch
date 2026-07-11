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
                    ['code' => 'FGHIJ', 'status' => 'p', 'total' => '99.00', 'currency' => 'EUR', 'email' => 'b@x.de', 'payments' => [['provider' => 'paypal']]],
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

    public function test_events_are_auto_created_from_pretix_and_transactions_assigned_by_slug(): void
    {
        Http::fake([
            '*/events/sportfest/orders/*' => Http::response(['results' => [], 'next' => null]),
            '*/events/*' => Http::response([
                'results' => [['slug' => 'sportfest', 'name' => ['de' => 'Großes Sommersportfest des SV 2026']]],
                'next' => null,
            ]),
        ]);

        $auto = $this->tx('Order SPORTFEST-AAAAA', 10.00);
        $manualEvent = \App\Models\Event::create(['name' => 'Manuell']);
        $manual = $this->tx('Order SPORTFEST-BBBBB', 10.00);
        $manual->update(['event_id' => $manualEvent->id, 'assignment_method' => 'manual']);

        app(PretixOrderImporter::class)->import($this->connection());

        $event = \App\Models\Event::where('pretix_event_slug', 'sportfest')->firstOrFail();
        $this->assertSame('Großes Sommersportfest des SV 2026', $event->name);

        $auto->refresh();
        $this->assertSame($event->id, $auto->event_id);
        $this->assertSame('pretix', $auto->assignment_method);

        // Manual assignments are never overwritten.
        $this->assertSame($manualEvent->id, $manual->fresh()->event_id);
    }

    public function test_pagination_follows_next_across_pages_without_looping(): void
    {
        // Regression: previously the client re-sent page_size on the "next" URL,
        // which clobbered its page parameter and looped on page 1 forever.
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/orders/')) {
                if (str_contains($url, 'page=2')) {
                    return Http::response([
                        'results' => [['code' => 'PAGE2', 'status' => 'p', 'total' => '10.00', 'payments' => [['provider' => 'banktransfer']]]],
                        'next' => null,
                    ]);
                }

                return Http::response([
                    'results' => [['code' => 'PAGE1', 'status' => 'p', 'total' => '20.00', 'payments' => [['provider' => 'paypal']]]],
                    'next' => 'https://pretix.eu/api/v1/organizers/verein/events/sportfest/orders/?page=2&page_size=50',
                ]);
            }

            return Http::response(['results' => [['slug' => 'sportfest', 'name' => 'Sportfest']], 'next' => null]);
        });

        $summary = app(PretixOrderImporter::class)->import($this->connection());

        $this->assertSame(2, $summary['orders']);
        $this->assertEqualsCanonicalizing(['PAGE1', 'PAGE2'], PretixOrder::pluck('order_code')->all());
        // Negative provider-extraction case: banktransfer is not PayPal.
        $this->assertFalse(PretixOrder::where('order_code', 'PAGE2')->first()->isPaypal());
    }

    public function test_ledger_events_sharing_the_order_code_do_not_cause_a_false_mismatch(): void
    {
        // Real case (pretix order QCVSY): a payment plus a hold (T2101) and its
        // release (T2102, positive!) all carry the same order code. Only the
        // payment must count towards the amount paid.
        Http::fake([
            '*/events/sportfest/orders/*' => Http::response([
                'results' => [['code' => 'ABCDE', 'status' => 'p', 'total' => '100.00', 'payments' => [['provider' => 'paypal']]]],
                'next' => null,
            ]),
            '*/events/*' => Http::response(['results' => [['slug' => 'sportfest', 'name' => 'Sportfest']], 'next' => null]),
        ]);

        $payment = $this->tx('Order SPORTFEST-ABCDE', 100.00); // T0006 by default
        $hold = $this->tx('Order SPORTFEST-ABCDE', -30.00);
        $hold->update(['transaction_event_code' => 'T2101']);
        $release = $this->tx('Order SPORTFEST-ABCDE', 30.00);
        $release->update(['transaction_event_code' => 'T2102']);

        $summary = app(PretixOrderImporter::class)->import($this->connection());

        $this->assertSame(1, $summary['matched']);   // the single payment
        $this->assertSame(0, $summary['mismatch']);
        $this->assertSame(Transaction::RECONCILIATION_MATCHED, $payment->fresh()->reconciliation_status);
        // Ledger rows are linked (deep-link) but carry no reconciliation status.
        $this->assertNull($hold->fresh()->reconciliation_status);
        $this->assertNull($release->fresh()->reconciliation_status);
        $this->assertNotNull($hold->fresh()->pretix_order_id);
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
        $this->assertTrue(PretixOrder::where('order_code', 'FGHIJ')->first()->isPaypal());
    }
}
