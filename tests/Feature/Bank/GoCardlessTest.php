<?php

namespace Tests\Feature\Bank;

use App\Models\BankConnection;
use App\Models\BankTransaction;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Services\Bank\GoCardlessMapper;
use App\Services\Bank\GoCardlessSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoCardlessTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapper_normalizes_a_booked_transaction(): void
    {
        $mapped = app(GoCardlessMapper::class)->map([
            [
                'transactionId' => 'gc-1',
                'bookingDate' => '2026-07-03',
                'valueDate' => '2026-07-03',
                'transactionAmount' => ['amount' => '4231.10', 'currency' => 'EUR'],
                'debtorName' => 'PayPal Europe',
                'debtorAccount' => ['iban' => 'DE00PAYPAL'],
                'remittanceInformationUnstructured' => 'PayPal Auszahlung',
                'endToEndId' => 'E2E-9',
            ],
            [
                'internalTransactionId' => 'gc-2',
                'bookingDate' => '2026-07-05',
                'transactionAmount' => ['amount' => '-99.99', 'currency' => 'EUR'],
                'creditorName' => 'Vermieter',
                'remittanceInformationUnstructured' => 'Miete',
            ],
        ]);

        $this->assertCount(2, $mapped);
        $this->assertSame(4231.10, $mapped[0]['amount']);
        $this->assertSame('PayPal Europe', $mapped[0]['counterparty_name']);
        $this->assertSame('E2E-9', $mapped[0]['end_to_end_id']);
        $this->assertSame(-99.99, $mapped[1]['amount']);        // debit stays negative
        $this->assertSame('Vermieter', $mapped[1]['counterparty_name']);
    }

    public function test_sync_pulls_transactions_and_reconciles_a_payout(): void
    {
        Http::fake([
            '*/token/new/' => Http::response(['access' => 'tok', 'access_expires' => 86400], 200),
            '*/accounts/*/transactions/' => Http::response([
                'transactions' => ['booked' => [[
                    'transactionId' => 'gc-1',
                    'bookingDate' => '2026-07-03', 'valueDate' => '2026-07-03',
                    'transactionAmount' => ['amount' => '500.00', 'currency' => 'EUR'],
                    'debtorName' => 'PayPal', 'remittanceInformationUnstructured' => 'Auszahlung',
                ]]],
            ], 200),
        ]);

        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'live', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'transaction_id' => 'PO', 'transaction_event_code' => 'T2001',
            'gross_amount' => -500.00, 'net_amount' => -500.00, 'currency' => 'EUR',
            'transaction_initiation_date' => '2026-07-01',
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'p'), 'dedupe_key' => hash('sha256', 'pd'), 'imported_at' => now(),
        ]);

        $connection = BankConnection::current();
        $connection->update([
            'secret_id' => 'sid', 'secret_key' => 'skey',
            'account_ids' => ['acc-123'], 'status' => BankConnection::STATUS_CONNECTED,
        ]);

        $result = app(GoCardlessSync::class)->sync($connection);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame(BankTransaction::METHOD_PAYOUT, BankTransaction::first()->match_method);
        $this->assertNotNull($connection->fresh()->last_synced_at);

        // Second sync of the same data -> deduped, nothing new.
        $again = app(GoCardlessSync::class)->sync($connection);
        $this->assertSame(0, $again['imported']);
        $this->assertSame(1, BankTransaction::count());
    }

    public function test_sync_requires_a_connected_connection(): void
    {
        $this->expectException(\RuntimeException::class);
        app(GoCardlessSync::class)->sync(BankConnection::current());
    }

    public function test_bad_credentials_surface_a_clear_error(): void
    {
        Http::fake(['*/token/new/' => Http::response(['detail' => 'invalid'], 401)]);

        $connection = BankConnection::current();
        $connection->update([
            'secret_id' => 'bad', 'secret_key' => 'bad',
            'account_ids' => ['acc-1'], 'status' => BankConnection::STATUS_CONNECTED,
        ]);

        $result = app(GoCardlessSync::class)->syncSafely($connection);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('GoCardless-Anmeldung fehlgeschlagen', $connection->fresh()->last_error);
    }
}
