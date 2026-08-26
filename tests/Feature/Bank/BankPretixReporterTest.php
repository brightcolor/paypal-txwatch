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

    /**
     * The event behind the slug the orders use - and its switch.
     *
     * Needed in every test that expects a confirmation: since the switch moved from
     * the connection to the event, an order without an Event row can never be
     * confirmed automatically, and that is the point.
     */
    private function eventWithSwitch(bool $an): \App\Models\Event
    {
        return \App\Models\Event::create([
            'name' => 'Sommerfest',
            'pretix_event_slug' => 'sommerfest',
            'is_active' => true,
            'auto_mark_paid' => $an,
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

    /**
     * THE EVENT'S SWITCH CONFIRMS ON IMPORT - the connection's no longer does.
     *
     * The behaviour change this test exists for: an organizer-wide flag could not
     * exclude a finished event or one whose payments run elsewhere.
     */
    public function test_the_event_switch_reports_on_import(): void
    {
        Http::fake([
            '*/orders/ZZ123/payments/' => Http::response(['results' => [
                ['local_id' => 2, 'provider' => 'manual', 'state' => 'pending', 'amount' => '40.00'],
            ]], 200),
            '*/payments/2/confirm/' => Http::response([], 200),
        ]);

        $c = $this->connection(auto: true);
        $this->eventWithSwitch(true);
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
    /**
     * THE CONNECTION FLAG ALONE CONFIRMS NOTHING ANY MORE.
     *
     * The guard against the old rule creeping back: `auto_confirm_bank_transfers` is
     * still on the connection and still set on this installation. If anything ever
     * reads it again, an entire organizer starts writing to pretix without a single
     * event having been armed.
     */
    public function test_the_connection_flag_alone_does_not_confirm(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $c = $this->connection(auto: true);
        $this->eventWithSwitch(false);
        $this->pendingOrder($c, 'OFFXX', 40.00, 'manual');
        $bank = $this->credit(40.00, 'Ueberweisung OFFXX');

        app(BankPretixReporter::class)->propose();

        $this->assertSame(
            BankTransaction::REPORT_PROPOSED,
            $bank->fresh()->pretix_report_status,
            'Ohne Event-Schalter darf nichts gemeldet werden, auch nicht mit gesetztem Verbindungs-Flag.',
        );

        $confirmation = \App\Models\PretixPaymentConfirmation::query()->latest('id')->first();
        $this->assertNotNull($confirmation, 'Auch eine Verweigerung muss aufgezeichnet werden.');
        $this->assertSame(\App\Models\PretixPaymentConfirmation::REASON_SWITCH_OFF, $confirmation->reason);
    }

    /**
     * A REFUSAL IS NOT A FAILURE.
     *
     * "The event is not automated" is the configuration working. Marking the bank row
     * FAILED for it would light up a red error on every credit of every non-automated
     * event and bury the ones that really went wrong.
     */
    public function test_a_switched_off_event_does_not_mark_the_row_failed(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $c = $this->connection();
        $this->eventWithSwitch(false);
        $this->pendingOrder($c, 'QUIET', 15.00);
        $bank = $this->credit(15.00, 'QUIET');

        app(BankPretixReporter::class)->propose();

        $this->assertNotSame(BankTransaction::REPORT_FAILED, $bank->fresh()->pretix_report_status);
        $this->assertNull($bank->fresh()->pretix_report_error);
    }

    /**
     * A HAND-CONFIRMED PAYMENT IS NOT BLOCKED BY THE SWITCH.
     *
     * The switch answers "may TxWatch do this on its own". Someone clicking confirm
     * has answered that for this one order; refusing the click would make the switch
     * mean something different from what it says.
     */
    public function test_a_manual_confirmation_ignores_the_switch(): void
    {
        Http::fake([
            '*/orders/BYHAND/payments/' => Http::response(['results' => [
                ['local_id' => 7, 'provider' => 'banktransfer', 'state' => 'pending', 'amount' => '12.00'],
            ]], 200),
            '*/payments/7/confirm/' => Http::response([], 200),
        ]);

        $c = $this->connection();
        $this->eventWithSwitch(false);
        $this->pendingOrder($c, 'BYHAND', 12.00);
        $bank = $this->credit(12.00, 'BYHAND');
        app(BankPretixReporter::class)->propose();

        $res = app(BankPretixReporter::class)->confirm($bank->fresh());

        $this->assertTrue($res['success']);
        $this->assertSame(BankTransaction::REPORT_REPORTED, $bank->fresh()->pretix_report_status);

        $confirmation = \App\Models\PretixPaymentConfirmation::query()
            ->where('outcome', \App\Models\PretixPaymentConfirmation::OUTCOME_CONFIRMED)->first();

        $this->assertNotNull($confirmation);
        $this->assertFalse($confirmation->automatic, 'Eine Handbestätigung darf nicht als Automatik gelten.');
    }

    /**
     * ONE CONFIRMATION PER ORDER, EVER.
     *
     * The double-payment case makes this real: two credits carrying the same code
     * would otherwise confirm twice, and the second one books money the order does
     * not owe.
     */
    public function test_an_order_is_never_confirmed_twice(): void
    {
        Http::fake([
            '*/orders/TWICE/payments/' => Http::response(['results' => [
                ['local_id' => 9, 'provider' => 'banktransfer', 'state' => 'pending', 'amount' => '20.00'],
            ]], 200),
            '*/payments/9/confirm/' => Http::response([], 200),
        ]);

        $c = $this->connection();
        $this->eventWithSwitch(true);
        $this->pendingOrder($c, 'TWICE', 20.00);

        $first = $this->credit(20.00, 'Zahlung TWICE');
        $second = $this->credit(20.00, 'Nochmal TWICE');

        app(BankPretixReporter::class)->propose();
        app(BankPretixReporter::class)->confirm($second->fresh(), automatic: true);

        $this->assertSame(
            1,
            \App\Models\PretixPaymentConfirmation::query()
                ->where('outcome', \App\Models\PretixPaymentConfirmation::OUTCOME_CONFIRMED)->count(),
            'Eine Bestellung darf nur einmal als bezahlt gemeldet werden.',
        );
    }
}
