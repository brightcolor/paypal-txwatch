<?php

namespace Tests\Feature\Sync;

use App\Models\PaypalAccount;
use App\Models\SyncRun;
use App\Models\Transaction;
use App\Services\Sync\CsvImportService;
use App\Services\Sync\CsvTransactionNormalizer;
use App\Services\Sync\EventAssigner;
use App\Services\Sync\TransactionUpserter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsvImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function account(): PaypalAccount
    {
        return PaypalAccount::create([
            'name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y', 'default_currency' => 'EUR',
        ]);
    }

    private function service(): CsvImportService
    {
        return new CsvImportService(new CsvTransactionNormalizer(), new TransactionUpserter(new EventAssigner()));
    }

    private function mapping(): array
    {
        return [
            'transaction_id' => 'Transaction ID', 'date' => 'Date', 'time' => null, 'gross' => 'Gross',
            'fee' => 'Fee', 'net' => 'Net', 'currency' => 'Currency', 'name' => 'Name', 'email' => null,
            'status' => 'Status', 'custom_field' => 'Custom Number', 'invoice_id' => null,
            'subject' => null, 'note' => null,
        ];
    }

    public function test_it_imports_valid_rows_and_records_a_sync_run(): void
    {
        $account = $this->account();

        $rows = [
            ['Transaction ID' => 'TXN1', 'Date' => '01.06.2026', 'Gross' => '100,00', 'Fee' => '-3,00', 'Net' => '97,00', 'Currency' => 'EUR', 'Name' => 'Max', 'Status' => 'Completed', 'Custom Number' => 'EVT-1'],
            ['Transaction ID' => 'TXN2', 'Date' => '02.06.2026', 'Gross' => '50,00', 'Fee' => '-1,50', 'Net' => '48,50', 'Currency' => 'EUR', 'Name' => 'Erika', 'Status' => 'Completed', 'Custom Number' => 'EVT-2'],
        ];

        $run = $this->service()->import($account, $rows, $this->mapping());

        $this->assertSame(SyncRun::TYPE_CSV_IMPORT, $run->type);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(2, $run->imported_count);
        $this->assertSame(0, $run->error_count);
        $this->assertSame(2, Transaction::count());
    }

    public function test_rows_missing_transaction_id_are_recorded_as_errors_not_silently_dropped(): void
    {
        $account = $this->account();

        $rows = [
            ['Transaction ID' => '', 'Date' => '01.06.2026', 'Gross' => '100,00'],
            ['Transaction ID' => 'TXN2', 'Date' => '02.06.2026', 'Gross' => '50,00'],
        ];

        $run = $this->service()->import($account, $rows, $this->mapping());

        $this->assertSame(1, $run->imported_count);
        $this->assertSame(1, $run->error_count);
        $this->assertSame(SyncRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(1, $run->importErrors()->count());
    }

    public function test_reimporting_the_same_csv_is_idempotent(): void
    {
        $account = $this->account();
        $rows = [['Transaction ID' => 'TXN1', 'Date' => '01.06.2026', 'Gross' => '100,00', 'Fee' => '-3,00']];

        $this->service()->import($account, $rows, $this->mapping());
        $run2 = $this->service()->import($account, $rows, $this->mapping());

        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, $run2->skipped_count);
    }
}
