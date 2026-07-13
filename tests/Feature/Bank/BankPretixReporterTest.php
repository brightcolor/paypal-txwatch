<?php

namespace Tests\Feature\Bank;

use App\Models\BankTransaction;
use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Services\Bank\BankPretixReporter;
use App\Services\Bank\BankStatementImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BankPretixReporterTest extends TestCase
{
    use RefreshDatabase;

    private function connection(bool $auto = false): PretixConnection
    {
        return PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein',
            'api_token' => 'tok', 'is_active' => true, 'auto_confirm_bank_transfers' => $auto,
        ]);
    }

    private function pendingOrder(PretixConnection $c, string $code, float $total, string $provider = 'banktransfer'): PretixOrder
    {
        return PretixOrder::create([
            'pretix_connection_id' => $c->id, 'event_slug' => 'sommerfest', 'order_code' => $code,
            'status' => 'n', 'payment_provider' => $provider, 'total' => $total, 'currency' => 'EUR',
            'url' => 'https://x/', 'raw_payload' => [],
        ]);
    }

    private function credit(float $amount, string $purpose): BankTransaction
    {
        static $n = 0;
        $n++;

        return BankTransaction::create([
            'valued_on' => '2026-07-05', 'amount' => $amount, 'currency' => 'EUR', 'purpose' => $purpose,
            'import_hash' => hash('sha256', 'h' . $n . $purpose),
            'reconciliation_status' => BankTransaction::STATUS_UNMATCHED,
        ]);
    }

    public function test_proposes_a_pending_order_matched_by_amount_and_code(): void
    {
        $c = $this->connection();
        $this->pendingOrder($c, 'ABCDE', 25.00);
        $bank = $this->credit(25.00, 'Sommerfest Bestellung ABCDE danke');

        $n = app(BankPretixReporter::class)->propose();

        $this->assertSame(1, $n);
        $bank->refresh();
        $this->assertSame(BankTransaction::REPORT_PROPOSED, $bank->pretix_report_status);
        $this->assertSame('ABCDE', $bank->pretix_order_code);
    }

    public function test_no_proposal_on_amount_mismatch_or_missing_code(): void
    {
        $c = $this->connection();
        $this->pendingOrder($c, 'ABCDE', 25.00);
        $this->credit(24.00, 'ABCDE');        // amount off
        $this->credit(25.00, 'kein code hier'); // code missing

        $this->assertSame(0, app(BankPretixReporter::class)->propose());
    }

    public function test_paypal_orders_are_not_proposed(): void
    {
        $c = $this->connection();
        $this->pendingOrder($c, 'PPPPP', 30.00, 'paypal');
        $this->credit(30.00, 'PPPPP');

        $this->assertSame(0, app(BankPretixReporter::class)->propose());
    }

    public function test_confirm_calls_pretix_and_marks_reported(): void
    {
        Http::fake([
            '*/orders/ABCDE/payments/' => Http::response(['results' => [
                ['local_id' => 1, 'provider' => 'banktransfer', 'state' => 'pending', 'amount' => '25.00'],
            ]], 200),
            '*/payments/1/confirm/' => Http::response([], 200),
        ]);

        $c = $this->connection();
        $this->pendingOrder($c, 'ABCDE', 25.00);
        $bank = $this->credit(25.00, 'ABCDE');
        app(BankPretixReporter::class)->propose();

        $res = app(BankPretixReporter::class)->confirm($bank->fresh());

        $this->assertTrue($res['success']);
        $this->assertSame(BankTransaction::REPORT_REPORTED, $bank->fresh()->pretix_report_status);
    }

    public function test_auto_confirm_connection_reports_on_import(): void
    {
        Http::fake([
            '*/orders/ZZ123/payments/' => Http::response(['results' => [
                ['local_id' => 2, 'provider' => 'manual', 'state' => 'pending', 'amount' => '40.00'],
            ]], 200),
            '*/payments/2/confirm/' => Http::response([], 200),
        ]);

        $c = $this->connection(auto: true);
        $this->pendingOrder($c, 'ZZ123', 40.00, 'manual');

        // Import a CAMT credit that references the order.
        $camt = '<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">'
            . '<BkToCstmrStmt><Stmt><Ntry><Amt Ccy="EUR">40.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>'
            . '<ValDt><Dt>2026-07-05</Dt></ValDt><NtryDtls><TxDtls><RmtInf><Ustrd>Ueberweisung ZZ123</Ustrd>'
            . '</RmtInf></TxDtls></NtryDtls></Ntry></Stmt></BkToCstmrStmt></Document>';

        $result = app(BankStatementImporter::class)->import($camt);

        $this->assertSame(1, $result['pretix_proposed']);
        $bank = BankTransaction::first();
        $this->assertSame(BankTransaction::REPORT_REPORTED, $bank->pretix_report_status);
    }

    public function test_confirm_fails_gracefully_without_permission(): void
    {
        Http::fake([
            '*/orders/NOPERM/payments/' => Http::response(['results' => [
                ['local_id' => 3, 'provider' => 'banktransfer', 'state' => 'pending', 'amount' => '10.00'],
            ]], 200),
            '*/payments/3/confirm/' => Http::response(['detail' => 'forbidden'], 403),
        ]);

        $c = $this->connection();
        $this->pendingOrder($c, 'NOPERM', 10.00);
        $bank = $this->credit(10.00, 'NOPERM');
        app(BankPretixReporter::class)->propose();

        $res = app(BankPretixReporter::class)->confirm($bank->fresh());

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Bestellungen ändern', $res['message']);
        $this->assertSame(BankTransaction::REPORT_FAILED, $bank->fresh()->pretix_report_status);
    }
}
