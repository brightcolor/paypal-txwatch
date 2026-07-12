<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\PaypalAccount;
use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Models\Transaction;
use App\Services\Export\SettlementBuilder;
use App\Services\Pretix\PretixReconciler;
use App\Services\Pretix\PretixTransactionBooker;
use App\Services\Reporting\ReportService;
use App\Services\Sync\TransactionUpserter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression net for the 2026-07-12 correctness audit: revision double
 * counting, PayPal-balance bridge, pretix booking math, slug collisions,
 * cross-connection reconciliation.
 */
class CorrectnessAuditTest extends TestCase
{
    use RefreshDatabase;

    private PaypalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
    }

    private function normalized(array $overrides = []): array
    {
        static $n = 0;
        $n++;

        $base = [
            'paypal_account_id' => $this->account->id,
            'transaction_id' => 'TX1',
            'transaction_event_code' => 'T0006',
            'transaction_status' => 'P',
            'transaction_initiation_date' => now()->subDays(3),
            'transaction_updated_date' => now()->subDays(3),
            'gross_amount' => 100.0,
            'fee_amount' => -3.0,
            'net_amount' => 97.0,
            'currency' => 'EUR',
            'paypal_reference_id' => null,
            'custom_field' => null,
            'raw_payload' => ['seq' => $n],
        ];

        $merged = array_merge($base, $overrides);
        $merged['raw_hash'] = hash('sha256', json_encode($merged['raw_payload']) . $n);
        $merged['dedupe_key'] = hash('sha256', 'dk' . $n . json_encode($overrides));

        return $merged;
    }

    public function test_a_new_revision_supersedes_the_old_one_and_sums_count_once(): void
    {
        $upserter = app(TransactionUpserter::class);

        // Pending revision, then the status-S revision of the SAME payment.
        $upserter->upsert($this->account, $this->normalized(['transaction_status' => 'P', 'transaction_updated_date' => now()->subDays(2)]));
        $upserter->upsert($this->account, $this->normalized(['transaction_status' => 'S', 'transaction_updated_date' => now()->subDay()]));

        $this->assertSame(2, Transaction::count());
        $this->assertSame(1, Transaction::query()->currentRevision()->count());
        $this->assertSame('S', Transaction::query()->currentRevision()->first()->transaction_status);

        // Revenue counts the payment ONCE (100, not 200).
        $byMonth = app(ReportService::class)->feesByMonth();
        $this->assertSame(100.0, (float) $byMonth->sum('gross'));
    }

    public function test_out_of_order_older_revision_never_displaces_newer_data(): void
    {
        $upserter = app(TransactionUpserter::class);

        $upserter->upsert($this->account, $this->normalized(['transaction_status' => 'S', 'transaction_updated_date' => now()->subDay()]));
        // Late re-sync delivers the OLD pending revision afterwards.
        $upserter->upsert($this->account, $this->normalized(['transaction_status' => 'P', 'transaction_updated_date' => now()->subDays(5)]));

        $current = Transaction::query()->currentRevision()->get();
        $this->assertCount(1, $current);
        $this->assertSame('S', $current->first()->transaction_status);
    }

    public function test_manual_event_assignment_survives_a_new_revision(): void
    {
        $event = Event::create(['name' => 'Manuell', 'is_active' => true]);
        $upserter = app(TransactionUpserter::class);

        $upserter->upsert($this->account, $this->normalized(['transaction_updated_date' => now()->subDays(2)]));
        Transaction::first()->update(['event_id' => $event->id, 'assignment_method' => 'manual', 'assigned_at' => now()]);

        $upserter->upsert($this->account, $this->normalized(['transaction_status' => 'S', 'transaction_updated_date' => now()]));

        $current = Transaction::query()->currentRevision()->first();
        $this->assertSame($event->id, $current->event_id);
        $this->assertSame('manual', $current->assignment_method);
    }

    public function test_deposits_and_currency_conversions_are_ledger_not_revenue(): void
    {
        foreach ([['T0300', 1000.0], ['T0200', -100.0], ['T0006', 50.0]] as $i => [$code, $gross]) {
            Transaction::create([
                'paypal_account_id' => $this->account->id, 'transaction_id' => 'L' . $i,
                'transaction_event_code' => $code, 'gross_amount' => $gross, 'net_amount' => $gross, 'currency' => 'EUR',
                'transaction_initiation_date' => now(),
                'raw_payload' => [], 'raw_hash' => hash('sha256', 'l' . $i), 'dedupe_key' => hash('sha256', 'ld' . $i), 'imported_at' => now(),
            ]);
        }

        $this->assertSame(50.0, (float) Transaction::query()->excludingLedgerEvents()->sum('gross_amount'));
    }

    public function test_payout_bridge_excludes_pretix_bank_money(): void
    {
        $mk = function (array $attrs) {
            static $i = 0;
            $i++;
            Transaction::create(array_merge([
                'paypal_account_id' => $this->account->id, 'transaction_id' => 'B' . $i,
                'transaction_event_code' => 'T0006', 'currency' => 'EUR',
                'transaction_initiation_date' => now()->subDay(),
                'raw_payload' => [], 'raw_hash' => hash('sha256', 'b' . $i), 'dedupe_key' => hash('sha256', 'bd' . $i), 'imported_at' => now(),
            ], $attrs));
        };

        // PayPal sale net 97; pretix bank transfer net 499.80; payout -50.
        $mk(['gross_amount' => 100, 'fee_amount' => -3, 'net_amount' => 97]);
        $mk(['paypal_account_id' => null, 'transaction_event_code' => null, 'instrument_type' => 'pretix', 'gross_amount' => 500, 'fee_amount' => -0.2, 'net_amount' => 499.8]);
        $mk(['transaction_event_code' => 'T0400', 'gross_amount' => -50, 'net_amount' => -50]);

        $r = app(ReportService::class)->payoutReconciliation();

        $this->assertSame(97.0, $r['incoming_net']);          // PayPal only
        $this->assertSame(499.8, $r['pretix_direct']);        // shown separately
        $this->assertSame(47.0, $r['expected_balance']);      // 97 - 50, no pretix
    }

    public function test_settlement_dedupes_revisions_and_excludes_denied_and_pending(): void
    {
        $event = Event::create(['name' => 'E', 'is_active' => true]);
        $upserter = app(TransactionUpserter::class);

        $upserter->upsert($this->account, $this->normalized(['transaction_status' => 'P', 'transaction_updated_date' => now()->subDays(2), 'custom_field' => null]));
        $upserter->upsert($this->account, $this->normalized(['transaction_status' => 'S', 'transaction_updated_date' => now()->subDay()]));
        Transaction::query()->update(['event_id' => $event->id]);

        // A denied payment never brought money.
        Transaction::create([
            'paypal_account_id' => $this->account->id, 'event_id' => $event->id, 'transaction_id' => 'DENIED',
            'transaction_event_code' => 'T0006', 'transaction_status' => 'D',
            'gross_amount' => 999, 'net_amount' => 999, 'currency' => 'EUR',
            'transaction_initiation_date' => now(),
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'de'), 'dedupe_key' => hash('sha256', 'dd'), 'imported_at' => now(),
        ]);

        $data = app(SettlementBuilder::class)->build($event);

        $this->assertSame(1, $data['totals']['count']);        // one revision, no denied row
        $this->assertSame(100.0, $data['totals']['amount']);
        $this->assertSame(97.0, $data['totals']['payout']);
    }

    public function test_slug_prefix_collision_assigns_the_longer_slug_event(): void
    {
        $short = Event::create(['name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest', 'is_active' => true]);
        $long = Event::create(['name' => 'Sommerfest 2', 'pretix_event_slug' => 'sommerfest-2', 'is_active' => true]);

        $mk = function (string $customField, string $id) {
            Transaction::create([
                'paypal_account_id' => $this->account->id, 'transaction_id' => $id,
                'transaction_event_code' => 'T0006', 'custom_field' => $customField,
                'gross_amount' => 10, 'net_amount' => 10, 'currency' => 'EUR',
                'transaction_initiation_date' => now(),
                'raw_payload' => [], 'raw_hash' => hash('sha256', $id), 'dedupe_key' => hash('sha256', 'd' . $id), 'imported_at' => now(),
            ]);
        };

        $mk('Order SOMMERFEST-2-AB3CD', 'S2');
        $mk('Order SOMMERFEST-XY9ZQ', 'S1');

        // Run the importer's assignment step via reflection (private method).
        $importer = app(\App\Services\Pretix\PretixOrderImporter::class);
        $m = new \ReflectionMethod($importer, 'assignEvents');
        $m->invoke($importer);

        $this->assertSame($long->id, Transaction::where('transaction_id', 'S2')->first()->event_id);
        $this->assertSame($short->id, Transaction::where('transaction_id', 'S1')->first()->event_id);
    }

    public function test_reconciler_leaves_other_connections_and_freetext_untouched(): void
    {
        $a = PretixConnection::create(['name' => 'A', 'base_url' => 'https://x', 'organizer_slug' => 'a', 'api_token' => 't']);
        $b = PretixConnection::create(['name' => 'B', 'base_url' => 'https://y', 'organizer_slug' => 'b', 'api_token' => 't']);

        PretixOrder::create(['pretix_connection_id' => $a->id, 'event_slug' => 'cup', 'order_code' => 'AAAAA', 'status' => 'p', 'total' => 50, 'url' => 'https://x/', 'raw_payload' => []]);
        PretixOrder::create(['pretix_connection_id' => $b->id, 'event_slug' => 'gala', 'order_code' => 'BBBBB', 'status' => 'p', 'total' => 70, 'url' => 'https://y/', 'raw_payload' => []]);

        $mk = function (string $customField, string $id, array $attrs = []) {
            return Transaction::create(array_merge([
                'paypal_account_id' => $this->account->id, 'transaction_id' => $id,
                'transaction_event_code' => 'T0006', 'custom_field' => $customField,
                'gross_amount' => 50, 'net_amount' => 50, 'currency' => 'EUR',
                'transaction_initiation_date' => now(),
                'raw_payload' => [], 'raw_hash' => hash('sha256', $id), 'dedupe_key' => hash('sha256', 'd' . $id), 'imported_at' => now(),
            ], $attrs));
        };

        $mk('Order CUP-AAAAA', 'TA');
        $txB = $mk('Order GALA-BBBBB', 'TB', ['gross_amount' => 70, 'net_amount' => 70]);
        $freetext = $mk('Danke für alles!', 'TF');

        // Reconcile B first (matches its order), then A.
        app(PretixReconciler::class)->reconcile($b);
        $this->assertSame(Transaction::RECONCILIATION_MATCHED, $txB->fresh()->reconciliation_status);

        app(PretixReconciler::class)->reconcile($a);

        // A's run must not wipe B's match, and free text stays status-less.
        $this->assertSame(Transaction::RECONCILIATION_MATCHED, $txB->fresh()->reconciliation_status);
        $this->assertSame(Transaction::RECONCILIATION_MATCHED, Transaction::where('transaction_id', 'TA')->first()->reconciliation_status);
        $this->assertNull($freetext->fresh()->reconciliation_status);
    }

    public function test_booker_uses_confirmed_payments_not_mutable_total(): void
    {
        $connection = PretixConnection::create(['name' => 'V', 'base_url' => 'https://x', 'organizer_slug' => 'v', 'api_token' => 't', 'bank_transfer_fee_cents' => 20]);

        // Paid 100 by bank transfer; later a position was removed: total now 80
        // and a done refund of 20 exists. Received money stays 100.
        $order = PretixOrder::create([
            'pretix_connection_id' => $connection->id, 'event_slug' => 'cup', 'order_code' => 'CCCCC',
            'status' => 'p', 'total' => 80, 'currency' => 'EUR', 'payment_provider' => 'banktransfer',
            'url' => 'https://x/', 'raw_payload' => [
                'payments' => [['provider' => 'banktransfer', 'state' => 'confirmed', 'amount' => '100.00']],
                'refunds' => [['local_id' => 1, 'state' => 'done', 'amount' => '20.00', 'provider' => 'banktransfer']],
            ],
        ]);

        app(PretixTransactionBooker::class)->book($connection);

        $payment = Transaction::where('transaction_id', 'PRETIX-cup-CCCCC')->first();
        $refund = Transaction::where('transaction_id', 'like', 'PRETIX-R1-%')->first();

        $this->assertSame(100.0, (float) $payment->gross_amount);   // NOT 80
        $this->assertSame(-20.0, (float) $refund->gross_amount);
        // Net kept: 100 - 20 - 0.20 fee = 79.80
        $this->assertSame(79.8, round((float) $payment->net_amount + (float) $refund->net_amount, 2));
    }

    public function test_booker_books_only_the_non_paypal_share_of_mixed_orders(): void
    {
        $connection = PretixConnection::create(['name' => 'V', 'base_url' => 'https://x', 'organizer_slug' => 'v', 'api_token' => 't']);

        PretixOrder::create([
            'pretix_connection_id' => $connection->id, 'event_slug' => 'cup', 'order_code' => 'MIXED',
            'status' => 'p', 'total' => 100, 'currency' => 'EUR', 'payment_provider' => 'manual',
            'url' => 'https://x/', 'raw_payload' => [
                'payments' => [
                    ['provider' => 'paypal', 'state' => 'confirmed', 'amount' => '60.00'],
                    ['provider' => 'manual', 'state' => 'confirmed', 'amount' => '40.00'],
                    ['provider' => 'manual', 'state' => 'canceled', 'amount' => '100.00'],
                ],
            ],
        ]);

        app(PretixTransactionBooker::class)->book($connection);

        $payment = Transaction::where('transaction_id', 'PRETIX-cup-MIXED')->first();
        $this->assertSame(40.0, (float) $payment->gross_amount);   // only the manual share
    }

    public function test_booker_skips_fully_paypal_orders_and_paypal_refunds(): void
    {
        $connection = PretixConnection::create(['name' => 'V', 'base_url' => 'https://x', 'organizer_slug' => 'v', 'api_token' => 't']);

        PretixOrder::create([
            'pretix_connection_id' => $connection->id, 'event_slug' => 'cup', 'order_code' => 'PPPPP',
            'status' => 'p', 'total' => 50, 'currency' => 'EUR', 'payment_provider' => 'paypal',
            'url' => 'https://x/', 'raw_payload' => [
                'payments' => [['provider' => 'paypal', 'state' => 'confirmed', 'amount' => '50.00']],
                'refunds' => [['local_id' => 9, 'state' => 'done', 'amount' => '50.00', 'provider' => 'paypal']],
            ],
        ]);

        $result = app(PretixTransactionBooker::class)->book($connection);

        $this->assertSame(1, $result['skipped_paypal']);
        $this->assertSame(0, Transaction::count());   // neither payment nor refund booked
    }
}
