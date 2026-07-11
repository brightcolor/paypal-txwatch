<?php

namespace Tests\Unit\Export;

use App\Models\Event;
use App\Models\PaypalAccount;
use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Models\Transaction;
use App\Services\Export\SettlementBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_settlement_splits_sources_and_totals_the_payout(): void
    {
        $event = Event::create(['name' => 'Sportfest']);
        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        $connection = PretixConnection::create(['name' => 'V', 'base_url' => 'https://p.eu', 'organizer_slug' => 'v', 'api_token' => 'x']);
        $order = PretixOrder::create([
            'pretix_connection_id' => $connection->id, 'event_slug' => 'sportfest', 'order_code' => 'M1',
            'total' => 50.00, 'tax_total' => 5.00, 'url' => 'https://x/', 'raw_payload' => [],
        ]);

        $tx = fn (array $a) => Transaction::create(array_merge([
            'event_id' => $event->id, 'currency' => 'EUR', 'raw_payload' => [],
            'raw_hash' => hash('sha256', uniqid('', true)), 'dedupe_key' => hash('sha256', uniqid('', true)),
            'imported_at' => now(), 'transaction_initiation_date' => now(),
        ], $a));

        // PayPal payment 100 (fee -3), PayPal refund -10 (T1107), pretix
        // bank transfer 50 (fee -0.20, real tax 5.00), pretix refund -20,
        // plus a hold (ledger - excluded) and an irrelevant-marked payment.
        $tx(['paypal_account_id' => $account->id, 'transaction_id' => 'P1', 'transaction_event_code' => 'T0006', 'gross_amount' => 100, 'fee_amount' => -3, 'net_amount' => 97]);
        $tx(['paypal_account_id' => $account->id, 'transaction_id' => 'R1', 'transaction_event_code' => 'T1107', 'gross_amount' => -10, 'fee_amount' => 0.30, 'net_amount' => -9.70]);
        $tx(['transaction_id' => 'PRETIX-1', 'instrument_type' => 'pretix', 'gross_amount' => 50, 'fee_amount' => -0.20, 'net_amount' => 49.80, 'pretix_order_id' => $order->id]);
        $tx(['transaction_id' => 'PRETIX-R1', 'instrument_type' => 'pretix', 'gross_amount' => -20, 'fee_amount' => 0, 'net_amount' => -20, 'pretix_order_id' => $order->id]);
        $tx(['paypal_account_id' => $account->id, 'transaction_id' => 'H1', 'transaction_event_code' => 'T2101', 'gross_amount' => -30, 'net_amount' => -30]);
        $tx(['paypal_account_id' => $account->id, 'transaction_id' => 'X1', 'transaction_event_code' => 'T0006', 'gross_amount' => 999, 'net_amount' => 999, 'marked_irrelevant_at' => now()]);

        $s = (new SettlementBuilder())->build($event, 19.0);

        $labels = array_column($s['blocks'], 'label');
        $this->assertSame([
            'PayPal-Zahlungen',
            'PayPal-Erstattungen',
            'Überweisungen & weitere Zahlarten (pretix)',
            'Erstattungen (pretix)',
        ], $labels);

        $this->assertSame(4, $s['totals']['count']); // ledger + irrelevant excluded
        $this->assertSame(120.0, $s['totals']['amount']); // 100 -10 +50 -20
        $this->assertSame(-2.9, $s['totals']['fees']); // -3 +0.30 -0.20
        $this->assertSame(117.1, $s['totals']['payout']); // 97 -9.70 +49.80 -20

        // VAT: PayPal rows flat 19% (100 -> 15.97; -10 -> -1.60), pretix rows
        // real tax scaled (50/50 -> 5.00; -20/50 -> -2.00).
        $this->assertSame(round(15.97 - 1.60 + 5.00 - 2.00, 2), $s['totals']['vat']);
    }
}
