<?php

namespace Tests\Feature;

use App\Models\PaypalAccount;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionTypeTest extends TestCase
{
    use RefreshDatabase;

    private function tx(?string $code, float $gross = 10): Transaction
    {
        static $i = 0;
        $i++;
        $account = PaypalAccount::firstOrCreate(
            ['name' => 'Acc'],
            ['mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y'],
        );

        return Transaction::create([
            'paypal_account_id' => $account->id,
            'transaction_id' => 'TXN' . $i,
            'transaction_event_code' => $code,
            'gross_amount' => $gross,
            'currency' => 'EUR',
            'raw_payload' => [],
            'raw_hash' => hash('sha256', 'h' . $i),
            'dedupe_key' => hash('sha256', 'k' . $i),
            'imported_at' => now(),
        ]);
    }

    public function test_type_label_is_derived_from_the_code_group(): void
    {
        $this->assertSame('Zahlung', $this->tx('T0006')->typeLabel());
        $this->assertSame('Rückzahlung/Storno', $this->tx('T1107')->typeLabel());
        $this->assertSame('Auszahlung', $this->tx('T0400')->typeLabel());
        $this->assertSame('Reserve/Hold', $this->tx('T2107')->typeLabel());
        $this->assertSame('Sonstige', $this->tx('T9999')->typeLabel());
        $this->assertSame('–', $this->tx(null)->typeLabel());
    }

    public function test_excluding_ledger_events_covers_whole_groups_including_previously_missed_codes(): void
    {
        $this->tx('T0006');           // sale - kept
        $this->tx('T1107', -5);       // refund - kept (a negative sale)
        $this->tx('T0400', -100);     // withdrawal - excluded
        $this->tx('T2107', -20);      // reserve/hold (was missed by the old explicit list) - excluded
        $this->tx(null);              // no code - kept

        $kept = Transaction::query()->excludingLedgerEvents()->pluck('transaction_event_code')->all();

        $this->assertContains('T0006', $kept);
        $this->assertContains('T1107', $kept);
        $this->assertContains(null, $kept);
        $this->assertNotContains('T0400', $kept);
        $this->assertNotContains('T2107', $kept);
    }

    public function test_of_type_scope_filters_by_group(): void
    {
        $this->tx('T0400', -10);
        $this->tx('T2001', -10); // also "Auszahlung" (payout group)
        $this->tx('T0006');

        $this->assertSame(2, Transaction::query()->ofType('Auszahlung')->count());
        $this->assertSame(1, Transaction::query()->ofType('Zahlung')->count());
    }
}
