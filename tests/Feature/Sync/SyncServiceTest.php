<?php

namespace Tests\Feature\Sync;

use App\Models\Event;
use App\Models\EventAssignmentRule;
use App\Models\ImportError;
use App\Models\PaypalAccount;
use App\Models\SyncRun;
use App\Models\Transaction;
use App\Services\Sync\EventAssigner;
use App\Services\Sync\SyncService;
use App\Services\Sync\TransactionNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeAccount(): PaypalAccount
    {
        return PaypalAccount::create([
            'name' => 'Test Account',
            'mode' => 'sandbox',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'default_currency' => 'EUR',
        ]);
    }

    private function fakeOAuth(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'fake-token',
                'expires_in' => 32400,
            ], 200),
        ]);
    }

    private function txnRecord(string $id, string $customField = 'EVT-1', float $amount = 42.50, ?string $updated = null): array
    {
        return [
            'transaction_info' => [
                'transaction_id' => $id,
                'transaction_event_code' => 'T0000',
                'transaction_status' => 'S',
                'transaction_initiation_date' => '2026-06-01T10:00:00+0000',
                'transaction_updated_date' => $updated ?? '2026-06-01T10:00:00+0000',
                'transaction_amount' => ['currency_code' => 'EUR', 'value' => (string) $amount],
                'fee_amount' => ['currency_code' => 'EUR', 'value' => '-1.00'],
                'invoice_id' => 'INV-' . $id,
                'custom_field' => $customField,
                'paypal_reference_id' => 'REF-' . $id,
                'paypal_reference_id_type' => 'TXN',
            ],
            'payer_info' => [
                'email_address' => 'payer@example.com',
                'payer_name' => ['given_name' => 'Max', 'surname' => 'Mustermann'],
                'country_code' => 'DE',
            ],
        ];
    }

    public function test_it_imports_transactions_and_records_a_successful_sync_run(): void
    {
        $account = $this->makeAccount();
        $this->fakeOAuth();

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400], 200),
            '*/v1/reporting/transactions*' => Http::response([
                'transaction_details' => [
                    $this->txnRecord('TXN1'),
                    $this->txnRecord('TXN2'),
                ],
                'total_items' => 2,
                'total_pages' => 1,
            ], 200),
        ]);

        $service = new SyncService(new TransactionNormalizer(), new EventAssigner());
        $run = $service->run($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-05'), SyncRun::TYPE_MANUAL);

        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(2, $run->imported_count);
        $this->assertSame(0, $run->error_count);
        $this->assertSame(2, Transaction::count());
        $this->assertNotNull($account->fresh()->last_successful_sync_at);
    }

    public function test_it_deduplicates_exact_repeats_but_keeps_history_of_real_changes(): void
    {
        $account = $this->makeAccount();

        $updatedDate = '2026-06-01T10:00:00+0000';

        Http::fake(function ($request) use (&$updatedDate) {
            if (str_contains($request->url(), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'tok', 'expires_in' => 32400], 200);
            }

            return Http::response([
                'transaction_details' => [$this->txnRecord('TXN1', updated: $updatedDate)],
                'total_items' => 1,
                'total_pages' => 1,
            ], 200);
        });

        $service = new SyncService(new TransactionNormalizer(), new EventAssigner());
        $service->run($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-05'), SyncRun::TYPE_MANUAL);

        $this->assertSame(1, Transaction::count());

        // Second sync re-fetches the exact same record (overlapping lookback window) -> no duplicate.
        $run2 = $service->run($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-05'), SyncRun::TYPE_SCHEDULED);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, $run2->skipped_count);

        // Third sync: PayPal reports the transaction again but with an updated status/date -> new revision row.
        $updatedDate = '2026-06-02T09:00:00+0000';

        $run3 = $service->run($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-05'), SyncRun::TYPE_SCHEDULED);
        $this->assertSame(2, Transaction::count());
        $this->assertSame(1, $run3->updated_count);
    }

    public function test_it_assigns_transactions_to_events_via_custom_field_rule(): void
    {
        $account = $this->makeAccount();
        $event = Event::create(['name' => 'Sommerfest 2026']);
        EventAssignmentRule::create([
            'event_id' => $event->id,
            'match_type' => EventAssignmentRule::TYPE_CUSTOM_FIELD_CONTAINS,
            'pattern' => 'SOMMERFEST',
            'priority' => 10,
        ]);

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400], 200),
            '*/v1/reporting/transactions*' => Http::response([
                'transaction_details' => [$this->txnRecord('TXN1', customField: 'SOMMERFEST-42')],
                'total_items' => 1,
                'total_pages' => 1,
            ], 200),
        ]);

        $service = new SyncService(new TransactionNormalizer(), new EventAssigner());
        $service->run($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-05'), SyncRun::TYPE_MANUAL);

        $transaction = Transaction::first();
        $this->assertSame($event->id, $transaction->event_id);
        $this->assertSame('rule', $transaction->assignment_method);
    }

    public function test_resultset_too_large_triggers_window_splitting_and_still_imports(): void
    {
        $account = $this->makeAccount();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'tok', 'expires_in' => 32400], 200);
            }

            $query = [];
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            // Simulate: the full 5-day window is too large, but a 1-day slice succeeds.
            $start = Carbon::parse($query['start_date']);
            $end = Carbon::parse($query['end_date']);

            if ($start->diffInHours($end, absolute: true) > 24) {
                return Http::response([
                    'name' => 'RESULTSET_TOO_LARGE',
                    'message' => 'Too many results.',
                ], 400);
            }

            return Http::response([
                'transaction_details' => [$this->txnRecord('TXN-' . $start->format('Ymd'))],
                'total_items' => 1,
                'total_pages' => 1,
            ], 200);
        });

        $service = new SyncService(new TransactionNormalizer(), new EventAssigner());
        $run = $service->run($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-06'), SyncRun::TYPE_BACKFILL);

        // 5 daily slices, each contributing one transaction.
        $this->assertSame(5, Transaction::count());
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
    }

    public function test_31_day_limit_is_split_into_multiple_windows(): void
    {
        $account = $this->makeAccount();
        $requestedWindows = [];

        Http::fake(function ($request) use (&$requestedWindows) {
            if (str_contains($request->url(), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'tok', 'expires_in' => 32400], 200);
            }

            $query = [];
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $requestedWindows[] = [$query['start_date'], $query['end_date']];

            return Http::response(['transaction_details' => [], 'total_items' => 0, 'total_pages' => 1], 200);
        });

        $service = new SyncService(new TransactionNormalizer(), new EventAssigner());
        $service->run($account, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'), SyncRun::TYPE_BACKFILL);

        // 364 days / 31-day windows -> more than one API window requested.
        $this->assertGreaterThan(10, count($requestedWindows));
    }

    public function test_auth_failure_marks_sync_run_failed_and_stores_account_error(): void
    {
        $account = $this->makeAccount();

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $service = new SyncService(new TransactionNormalizer(), new EventAssigner());

        try {
            $service->run($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'), SyncRun::TYPE_MANUAL);
            $this->fail('Expected PayPalAuthException to be thrown.');
        } catch (\App\Services\PayPal\Exceptions\PayPalAuthException) {
            // expected
        }

        $run = SyncRun::first();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($account->fresh()->last_error);
    }
}
