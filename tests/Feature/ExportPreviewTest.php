<?php

namespace Tests\Feature;

use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Services\Export\ExportDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_view_renders_with_data(): void
    {
        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'transaction_id' => 'T1', 'transaction_event_code' => 'T0006',
            'gross_amount' => 100, 'fee_amount' => -2, 'net_amount' => 98, 'currency' => 'EUR',
            'transaction_initiation_date' => now(),
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'a'), 'dedupe_key' => hash('sha256', 'b'), 'imported_at' => now(),
        ]);

        $built = app(ExportDataBuilder::class)->build(Transaction::query()->limit(25), null, ['vat_rate' => 19.0]);

        $html = view('filament.export-preview', ['data' => $built, 'limit' => 25])->render();

        $this->assertStringContainsString('Vorschau der ersten 25 Zeilen', $html);
        $this->assertStringContainsString('Gesamt', $html);
    }
}
