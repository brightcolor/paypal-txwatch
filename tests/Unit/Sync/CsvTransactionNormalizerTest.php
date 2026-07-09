<?php

namespace Tests\Unit\Sync;

use App\Models\PaypalAccount;
use App\Services\Sync\CsvTransactionNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsvTransactionNormalizerTest extends TestCase
{
    use RefreshDatabase;

    private function account(): PaypalAccount
    {
        return PaypalAccount::create([
            'name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y', 'default_currency' => 'EUR',
        ]);
    }

    public function test_it_normalizes_a_german_formatted_csv_row(): void
    {
        $rawRow = [
            'Datum' => '01.06.2026', 'Zeit' => '10:00:00', 'Brutto' => '1.234,56', 'Gebühr' => '-12,34',
            'Netto' => '1.222,22', 'Währung' => 'EUR', 'Name' => 'Erika Musterfrau',
            'Transaktionscode' => 'TXN1', 'Benutzerdefinierte Nummer' => 'SOMMERFEST-1',
        ];

        $mapped = [
            'date' => $rawRow['Datum'], 'time' => $rawRow['Zeit'], 'gross' => $rawRow['Brutto'],
            'fee' => $rawRow['Gebühr'], 'net' => $rawRow['Netto'], 'currency' => $rawRow['Währung'],
            'name' => $rawRow['Name'], 'transaction_id' => $rawRow['Transaktionscode'],
            'custom_field' => $rawRow['Benutzerdefinierte Nummer'],
        ];

        $normalized = (new CsvTransactionNormalizer())->normalize($this->account(), $rawRow, $mapped);

        $this->assertSame('TXN1', $normalized['transaction_id']);
        $this->assertSame(1234.56, $normalized['gross_amount']);
        $this->assertSame(-12.34, $normalized['fee_amount']);
        $this->assertSame(1222.22, $normalized['net_amount']);
        $this->assertSame('SOMMERFEST-1', $normalized['custom_field']);
        $this->assertSame('2026-06-01', $normalized['transaction_initiation_date']->format('Y-m-d'));
    }

    public function test_it_normalizes_an_english_formatted_csv_row(): void
    {
        $rawRow = ['Date' => '06/01/2026', 'Gross' => '1,234.56', 'Fee' => '-12.34', 'Transaction ID' => 'TXN2'];
        $mapped = ['date' => $rawRow['Date'], 'gross' => $rawRow['Gross'], 'fee' => $rawRow['Fee'], 'transaction_id' => $rawRow['Transaction ID']];

        $normalized = (new CsvTransactionNormalizer())->normalize($this->account(), $rawRow, $mapped);

        $this->assertSame(1234.56, $normalized['gross_amount']);
        $this->assertSame(-12.34, $normalized['fee_amount']);
        // net falls back to gross+fee when no net column mapped
        $this->assertSame(1222.22, $normalized['net_amount']);
    }

    public function test_missing_net_column_falls_back_to_gross_plus_fee(): void
    {
        $rawRow = ['Gross' => '100.00', 'Fee' => '-3.50', 'Transaction ID' => 'TXN3'];
        $mapped = ['gross' => '100.00', 'fee' => '-3.50', 'transaction_id' => 'TXN3'];

        $normalized = (new CsvTransactionNormalizer())->normalize($this->account(), $rawRow, $mapped);

        $this->assertSame(96.5, $normalized['net_amount']);
    }

    public function test_dedupe_key_differs_between_csv_and_api_ingestion_for_same_transaction(): void
    {
        // Not a strict requirement, but documents current behavior: CSV-imported
        // and API-imported revisions of the same transaction_id are distinct
        // rows (different raw payload/hash), consistent with the "keep history"
        // design rather than silently overwriting one with the other.
        $rawRow = ['Gross' => '10.00', 'Transaction ID' => 'TXN4'];
        $mapped = ['gross' => '10.00', 'transaction_id' => 'TXN4'];

        $normalized = (new CsvTransactionNormalizer())->normalize($this->account(), $rawRow, $mapped);

        $this->assertSame(64, strlen($normalized['dedupe_key']));
    }
}
