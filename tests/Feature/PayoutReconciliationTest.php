<?php

namespace Tests\Feature;

use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Services\Reporting\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private PaypalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
    }

    private function tx(string $code, float $gross, float $fee = 0, float $net = null, array $extra = []): Transaction
    {
        static $n = 0;
        $n++;

        return Transaction::create(array_merge([
            'paypal_account_id' => $this->account->id,
            'transaction_id' => 'T' . $n,
            'transaction_event_code' => $code,
            'gross_amount' => $gross,
            'fee_amount' => $fee,
            'net_amount' => $net ?? ($gross + $fee),
            'currency' => 'EUR',
            'transaction_initiation_date' => now()->subDays(2),
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'h' . $n), 'dedupe_key' => hash('sha256', 'd' . $n),
            'imported_at' => now(),
        ], $extra));
    }

    public function test_payouts_scope_only_catches_t04_and_t20(): void
    {
        $this->tx('T0006', 100);      // payment
        $this->tx('T0400', -50);      // bank withdrawal
        $this->tx('T2001', -30);      // payout
        $this->tx('T2102', 10);       // hold release - NOT a payout

        $payouts = Transaction::query()->payouts()->get();
        $this->assertCount(2, $payouts);
        $this->assertEqualsCanonicalizing(['T0400', 'T2001'], $payouts->pluck('transaction_event_code')->all());
    }

    public function test_reconciliation_bridges_income_and_payouts(): void
    {
        // Income: 100 gross, -3 fee -> net 97
        $this->tx('T0006', 100, -3, 97);
        // Refund: -10
        $this->tx('T1107', -10, 0, -10);
        // Payout to bank: -50
        $this->tx('T0400', -50, 0, -50);

        $r = app(ReportService::class)->payoutReconciliation();

        $this->assertSame(90.0, $r['incoming_gross']);     // 100 - 10
        $this->assertSame(-3.0, $r['fees']);
        $this->assertSame(87.0, $r['incoming_net']);       // 97 - 10
        $this->assertSame(-10.0, $r['refunds']);
        $this->assertSame(-50.0, $r['payouts']);
        $this->assertSame(1, $r['payout_count']);
        $this->assertSame(37.0, $r['expected_balance']);   // 87 - 50
    }

    public function test_monthly_tax_summary_groups_by_month(): void
    {
        $this->tx('T0006', 119, -2, 117, ['transaction_initiation_date' => '2026-03-15']);
        $this->tx('T0006', 238, -4, 234, ['transaction_initiation_date' => '2026-03-20']);
        $this->tx('T0006', 119, -2, 117, ['transaction_initiation_date' => '2026-04-01']);

        $summary = app(ReportService::class)->monthlyTaxSummary(null, null, 19.0);

        $this->assertCount(2, $summary);
        $march = $summary->firstWhere('month', '2026-03');
        $this->assertSame(2, $march['count']);
        $this->assertSame(357.0, $march['gross']);
        // 19% VAT fallback on 357 gross = 357 - 357/1.19 = 57.0
        $this->assertEqualsWithDelta(57.0, $march['vat'], 0.05);
    }
}
