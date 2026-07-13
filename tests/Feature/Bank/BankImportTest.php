<?php

namespace Tests\Feature\Bank;

use App\Models\BankTransaction;
use App\Models\Event;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Services\Bank\BankStatementImporter;
use App\Services\Bank\BankStatementParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankImportTest extends TestCase
{
    use RefreshDatabase;

    private function camt(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt>
    <Stmt>
      <Ntry>
        <Amt Ccy="EUR">4231.10</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <BookgDt><Dt>2026-07-03</Dt></BookgDt>
        <ValDt><Dt>2026-07-03</Dt></ValDt>
        <AcctSvcrRef>REF-001</AcctSvcrRef>
        <NtryDtls><TxDtls>
          <Refs><EndToEndId>E2E-777</EndToEndId></Refs>
          <RltdPties>
            <Dbtr><Nm>PayPal Europe</Nm></Dbtr>
            <DbtrAcct><Id><IBAN>DE00PAYPAL0000000000</IBAN></Id></DbtrAcct>
          </RltdPties>
          <RmtInf><Ustrd>PayPal Auszahlung</Ustrd></RmtInf>
        </TxDtls></NtryDtls>
      </Ntry>
      <Ntry>
        <Amt Ccy="EUR">20.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <BookgDt><Dt>2026-07-04</Dt></BookgDt>
        <ValDt><Dt>2026-07-04</Dt></ValDt>
        <NtryDtls><TxDtls>
          <RltdPties><Dbtr><Nm>Max Mustermann</Nm></Dbtr></RltdPties>
          <RmtInf><Ustrd>Bestellung SOMMERFEST-ABCDE Danke</Ustrd></RmtInf>
        </TxDtls></NtryDtls>
      </Ntry>
      <Ntry>
        <Amt Ccy="EUR">99.99</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <BookgDt><Dt>2026-07-05</Dt></BookgDt>
        <ValDt><Dt>2026-07-05</Dt></ValDt>
        <NtryDtls><TxDtls><RmtInf><Ustrd>Miete</Ustrd></RmtInf></TxDtls></NtryDtls>
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
</Document>
XML;
    }

    public function test_camt_parser_reads_amounts_dates_and_direction(): void
    {
        $entries = app(BankStatementParser::class)->parseCamt053($this->camt());

        $this->assertCount(3, $entries);
        $this->assertSame(4231.10, $entries[0]['amount']);
        $this->assertSame('2026-07-03', $entries[0]['valued_on']);
        $this->assertSame('PayPal Europe', $entries[0]['counterparty_name']);
        $this->assertSame('E2E-777', $entries[0]['end_to_end_id']);
        $this->assertSame(-99.99, $entries[2]['amount']); // debit is negative
    }

    public function test_mt940_parser_reads_credit_and_debit(): void
    {
        $mt940 = ":20:STARTUMS\n"
            . ":25:12345678/1234567890\n"
            . ":28C:1/1\n"
            . ":60F:C260701EUR1000,00\n"
            . ":61:2607030703C4231,10NTRFREF//BANK1\n"
            . ":86:166?00GUTSCHRIFT?20PayPal Auszahlung?32PAYPAL EUROPE\n"
            . ":61:2607050705D99,99NTRFREF//BANK2\n"
            . ":86:105?00LASTSCHRIFT?20Miete\n"
            . ":62F:C260705EUR5131,11\n";

        $entries = app(BankStatementParser::class)->parseMt940($mt940);

        $this->assertCount(2, $entries);
        $this->assertSame(4231.10, $entries[0]['amount']);
        $this->assertSame('2026-07-03', $entries[0]['valued_on']);
        $this->assertStringContainsString('PayPal Auszahlung', $entries[0]['purpose']);
        $this->assertSame('PAYPAL EUROPE', $entries[0]['counterparty_name']);
        $this->assertSame(-99.99, $entries[1]['amount']);
    }

    public function test_import_dedupes_and_autolinks_payout_and_pretix(): void
    {
        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        $event = Event::create(['name' => 'Sommerfest', 'is_active' => true]);

        // PayPal payout of 4231.10 leaving the balance (negative gross), 2 days
        // before the bank value date.
        Transaction::create([
            'paypal_account_id' => $account->id, 'transaction_id' => 'PO1', 'transaction_event_code' => 'T0400',
            'gross_amount' => -4231.10, 'net_amount' => -4231.10, 'currency' => 'EUR',
            'transaction_initiation_date' => '2026-07-01 09:00:00',
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'p1'), 'dedupe_key' => hash('sha256', 'pd1'), 'imported_at' => now(),
        ]);

        // pretix bank transfer of 20.00, order code ABCDE.
        Transaction::create([
            'paypal_account_id' => null, 'event_id' => $event->id, 'transaction_id' => 'PRETIX-sommerfest-ABCDE',
            'transaction_event_code' => null, 'instrument_type' => 'pretix', 'gross_amount' => 20.00, 'net_amount' => 19.80,
            'currency' => 'EUR', 'custom_field' => 'Order SOMMERFEST-ABCDE', 'transaction_initiation_date' => '2026-07-04',
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'x1'), 'dedupe_key' => hash('sha256', 'xd1'), 'imported_at' => now(),
        ]);

        $result = app(BankStatementImporter::class)->import($this->camt());

        $this->assertSame(3, $result['imported']);
        $this->assertSame(2, $result['matched']); // payout + pretix credit (not the debit)

        $payoutRow = BankTransaction::where('end_to_end_id', 'E2E-777')->first();
        $this->assertSame(BankTransaction::STATUS_MATCHED, $payoutRow->reconciliation_status);
        $this->assertSame(BankTransaction::METHOD_PAYOUT, $payoutRow->match_method);

        $pretixRow = BankTransaction::where('amount', 20.00)->first();
        $this->assertSame(BankTransaction::METHOD_PRETIX, $pretixRow->match_method);

        // Re-import the same statement -> no duplicates, no new matches.
        $again = app(BankStatementImporter::class)->import($this->camt());
        $this->assertSame(0, $again['imported']);
        $this->assertSame(3, $again['skipped']);
        $this->assertSame(3, BankTransaction::count());
    }

    public function test_a_payout_is_never_matched_to_two_bank_lines(): void
    {
        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'transaction_id' => 'PO', 'transaction_event_code' => 'T0400',
            'gross_amount' => -50.00, 'net_amount' => -50.00, 'currency' => 'EUR',
            'transaction_initiation_date' => '2026-07-01',
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'p'), 'dedupe_key' => hash('sha256', 'pd'), 'imported_at' => now(),
        ]);

        foreach (['A', 'B'] as $i => $ref) {
            BankTransaction::create([
                'valued_on' => '2026-07-02', 'amount' => 50.00, 'currency' => 'EUR', 'purpose' => 'x',
                'end_to_end_id' => "E-$ref", 'import_hash' => hash('sha256', "h$ref"),
                'reconciliation_status' => BankTransaction::STATUS_UNMATCHED,
            ]);
        }

        app(\App\Services\Bank\BankReconciler::class)->reconcile();

        $this->assertSame(1, BankTransaction::where('reconciliation_status', BankTransaction::STATUS_MATCHED)->count());
    }
}
