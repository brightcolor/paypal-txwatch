<?php

namespace Tests\Feature;

use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Services\Export\ExportColumns;
use App\Services\Export\ExportDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_class_follows_the_app_scheme(): void
    {
        $this->assertSame('money-amt', ExportColumns::moneyClass('gross', 100.0));
        $this->assertSame('money-net', ExportColumns::moneyClass('net', 50.0));
        $this->assertSame('money-net', ExportColumns::moneyClass('net_excl_vat', 42.0));
        $this->assertSame('money-neg', ExportColumns::moneyClass('fee', -3.0));
        $this->assertSame('money-neg', ExportColumns::moneyClass('gross', -10.0)); // refund row
        $this->assertNull(ExportColumns::moneyClass('vat', 19.0));                  // neutral
        $this->assertNull(ExportColumns::moneyClass('name', 0.0));                  // non-money
    }

    public function test_pdf_renders_coloured_money_cells(): void
    {
        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'transaction_id' => 'T1', 'transaction_event_code' => 'T0006',
            'gross_amount' => 100, 'fee_amount' => -3, 'net_amount' => 97, 'currency' => 'EUR',
            'transaction_initiation_date' => now(),
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'a'), 'dedupe_key' => hash('sha256', 'b'), 'imported_at' => now(),
        ]);

        $built = app(ExportDataBuilder::class)->build(
            Transaction::query(),
            null,
            ['vat_rate' => 19.0, 'mode' => 'internal'],
        );

        $html = view('exports.pdf', $built)->render();

        $this->assertStringContainsString('money-amt', $html);   // Brutto
        $this->assertStringContainsString('money-net', $html);   // Nach Gebühren
        $this->assertStringContainsString('money-neg', $html);   // Gebühr (negativ)
    }
}
