<?php

namespace Tests\Unit\Reporting;

use App\Models\Event;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Services\Reporting\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $name = 'Acc'): PaypalAccount
    {
        return PaypalAccount::create([
            'name' => $name, 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y', 'default_currency' => 'EUR',
        ]);
    }

    private function transaction(array $overrides = []): Transaction
    {
        static $i = 0;
        $i++;

        return Transaction::create(array_merge([
            'paypal_account_id' => $this->account()->id,
            'transaction_id' => 'TXN' . $i,
            'transaction_initiation_date' => Carbon::parse('2026-06-01'),
            'gross_amount' => 100,
            'fee_amount' => -5,
            'net_amount' => 95,
            'currency' => 'EUR',
            'raw_payload' => [],
            'raw_hash' => hash('sha256', 'h' . $i),
            'dedupe_key' => hash('sha256', 'k' . $i),
            'imported_at' => now(),
        ], $overrides));
    }

    public function test_fees_by_event_groups_and_computes_ratio(): void
    {
        $event = Event::create(['name' => 'Sommerfest']);
        $account = $this->account();

        $this->transaction(['paypal_account_id' => $account->id, 'event_id' => $event->id, 'gross_amount' => 100, 'fee_amount' => -5, 'net_amount' => 95]);
        $this->transaction(['paypal_account_id' => $account->id, 'event_id' => $event->id, 'gross_amount' => 200, 'fee_amount' => -10, 'net_amount' => 190]);
        $this->transaction(['paypal_account_id' => $account->id, 'gross_amount' => 50, 'fee_amount' => -1, 'net_amount' => 49]);

        $result = (new ReportService())->feesByEvent();

        $sommerfest = $result->firstWhere('label', 'Sommerfest');
        $this->assertSame(2, $sommerfest['count']);
        $this->assertSame(300.0, $sommerfest['gross']);
        $this->assertSame(-15.0, $sommerfest['fee']);
        $this->assertSame(5.0, $sommerfest['fee_ratio']); // 15/300 = 5%

        $ohneEvent = $result->firstWhere('label', 'Ohne Event');
        $this->assertSame(1, $ohneEvent['count']);
    }

    public function test_custom_field_prefix_extraction(): void
    {
        // Real custom_field values follow PayPal's "Order <prefix>-<order-id>"
        // scheme, and the order-id is alphanumeric (not digits-only).
        $this->assertSame('GAG-WISMAR-2026', ReportService::extractPrefix('Order GAG-WISMAR-2026-SC3HR'));
        $this->assertSame('SOMMERFEST-2026', ReportService::extractPrefix('Order SOMMERFEST-2026-A1B2'));
        $this->assertSame('FOO', ReportService::extractPrefix('Order FOO-XYZ'));
        $this->assertSame('FOO', ReportService::extractPrefix('FOO'));
    }

    public function test_custom_field_prefixes_report_groups_by_prefix(): void
    {
        $this->transaction(['custom_field' => 'Order SOMMERFEST-2026-A1B2', 'gross_amount' => 100]);
        $this->transaction(['custom_field' => 'Order SOMMERFEST-2026-C3D4', 'gross_amount' => 50]);
        $this->transaction(['custom_field' => 'Order STADTFEST-2026-E5F6', 'gross_amount' => 30]);
        $this->transaction(['custom_field' => null]);

        $result = (new ReportService())->customFieldPrefixes();

        $sommerfest = $result->firstWhere('prefix', 'SOMMERFEST-2026');
        $this->assertSame(2, $sommerfest['count']);
        $this->assertSame(150.0, $sommerfest['gross']);

        $stadtfest = $result->firstWhere('prefix', 'STADTFEST-2026');
        $this->assertSame(1, $stadtfest['count']);
    }

    public function test_event_assignment_ratio(): void
    {
        $event = Event::create(['name' => 'E']);
        $this->transaction(['event_id' => $event->id]);
        $this->transaction(['event_id' => $event->id]);
        $this->transaction(['event_id' => null]);
        $this->transaction(['event_id' => null]);

        $ratio = (new ReportService())->eventAssignmentRatio();

        $this->assertSame(4, $ratio['total']);
        $this->assertSame(2, $ratio['assigned']);
        $this->assertSame(2, $ratio['unassigned']);
        $this->assertSame(50.0, $ratio['ratio']);
    }

    public function test_refunds_summary_counts_only_documented_refund_codes(): void
    {
        // T0000 with a negative amount is NOT a documented refund code (it isn't even a
        // withdrawal/hold code - just a plain payment code here) and must not be counted,
        // since sign-correlation alone previously misclassified withdrawals/holds as refunds.
        $this->transaction(['gross_amount' => -50, 'transaction_event_code' => 'T0000']);
        $this->transaction(['gross_amount' => -80, 'transaction_event_code' => 'T1107']);
        $this->transaction(['gross_amount' => 100, 'transaction_event_code' => 'T0000']);

        $summary = (new ReportService())->refundsSummary();

        $this->assertSame(1, $summary['count']);
        $this->assertSame(-80.0, $summary['total']);
    }

    public function test_ledger_only_events_are_excluded_from_reports(): void
    {
        // A bank withdrawal (T0400) is not a sale and must not appear in revenue-facing
        // report totals, even though it carries a (large, negative) gross_amount.
        $this->transaction(['gross_amount' => 100, 'transaction_event_code' => 'T0006']);
        $this->transaction(['gross_amount' => -5000, 'transaction_event_code' => 'T0400']);

        $ratio = (new ReportService())->eventAssignmentRatio();
        $this->assertSame(1, $ratio['total']);

        $fees = (new ReportService())->feesByMonth();
        $this->assertSame(1, $fees->sum('count'));
    }

    public function test_date_range_filters_are_applied(): void
    {
        $this->transaction(['transaction_initiation_date' => Carbon::parse('2026-01-01'), 'gross_amount' => 100]);
        $this->transaction(['transaction_initiation_date' => Carbon::parse('2026-06-01'), 'gross_amount' => 200]);

        $ratio = (new ReportService())->eventAssignmentRatio(Carbon::parse('2026-05-01'), Carbon::parse('2026-07-01'));

        $this->assertSame(1, $ratio['total']);
    }

    public function test_account_comparison_groups_by_account(): void
    {
        $a1 = $this->account('Konto A');
        $a2 = $this->account('Konto B');

        $this->transaction(['paypal_account_id' => $a1->id, 'gross_amount' => 100]);
        $this->transaction(['paypal_account_id' => $a2->id, 'gross_amount' => 300]);

        $result = (new ReportService())->accountComparison();

        $this->assertSame('Konto B', $result->first()['label']); // sorted by gross desc
        $this->assertSame(300.0, $result->first()['gross']);
    }
}
