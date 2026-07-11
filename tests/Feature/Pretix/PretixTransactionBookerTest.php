<?php

namespace Tests\Feature\Pretix;

use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Models\Transaction;
use App\Services\Pretix\PretixTransactionBooker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PretixTransactionBookerTest extends TestCase
{
    use RefreshDatabase;

    private function connection(array $overrides = []): PretixConnection
    {
        return PretixConnection::create(array_merge([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein',
            'api_token' => 'tok', 'bank_transfer_fee_cents' => 20,
        ], $overrides));
    }

    private function order(PretixConnection $c, array $overrides = []): PretixOrder
    {
        static $i = 0;
        $i++;

        return PretixOrder::create(array_merge([
            'pretix_connection_id' => $c->id,
            'event_slug' => 'sportfest',
            'order_code' => 'ORD' . $i,
            'status' => 'p',
            'payment_provider' => 'banktransfer',
            'email' => 'k@x.de',
            'total' => 50.00,
            'currency' => 'EUR',
            'order_datetime' => now()->subDay(),
            'url' => 'https://pretix.eu/control/event/verein/sportfest/orders/ORD' . $i . '/',
            'raw_payload' => [],
        ], $overrides));
    }

    public function test_paid_bank_transfer_order_is_booked_with_the_20_cent_fee(): void
    {
        $c = $this->connection();
        $order = $this->order($c, ['total' => 50.00]);

        $result = (new PretixTransactionBooker())->book($c);

        $this->assertSame(1, $result['booked']);

        $tx = Transaction::firstOrFail();
        $this->assertSame('50.00', $tx->gross_amount);
        $this->assertSame('-0.20', $tx->fee_amount);
        $this->assertSame('49.80', $tx->net_amount);
        $this->assertNull($tx->paypal_account_id);
        $this->assertSame('banktransfer', $tx->payment_method_type);
        $this->assertSame('Order SPORTFEST-' . $order->order_code, $tx->custom_field);
        $this->assertSame($order->id, $tx->pretix_order_id);
        $this->assertSame(Transaction::RECONCILIATION_MATCHED, $tx->reconciliation_status);
        $this->assertSame('Zahlung', $tx->typeLabel());
        $this->assertSame('S', $tx->transaction_status);
    }

    public function test_paypal_orders_are_skipped_by_default_but_bookable_via_toggle(): void
    {
        $c = $this->connection();
        $this->order($c, ['payment_provider' => 'paypal']);

        $result = (new PretixTransactionBooker())->book($c);
        $this->assertSame(0, $result['booked']);
        $this->assertSame(1, $result['skipped_paypal']);
        $this->assertSame(0, Transaction::count());

        $c->update(['import_paypal_orders' => true]);
        $result = (new PretixTransactionBooker())->book($c);
        $this->assertSame(1, $result['booked']);
    }

    public function test_unpaid_orders_are_not_booked_and_non_bank_transfer_gets_no_fee(): void
    {
        $c = $this->connection();
        $this->order($c, ['status' => 'n']);
        $this->order($c, ['payment_provider' => 'boxoffice', 'total' => 30.00]);

        (new PretixTransactionBooker())->book($c);

        $this->assertSame(1, Transaction::count());
        $tx = Transaction::firstOrFail();
        $this->assertSame('0.00', $tx->fee_amount);
        $this->assertSame('30.00', $tx->net_amount);
    }

    public function test_rebooking_is_idempotent_and_mirrors_a_later_cancellation(): void
    {
        $c = $this->connection();
        $order = $this->order($c);

        (new PretixTransactionBooker())->book($c);
        (new PretixTransactionBooker())->book($c);
        $this->assertSame(1, Transaction::count());

        $order->update(['status' => 'c']);
        (new PretixTransactionBooker())->book($c);

        $this->assertSame(1, Transaction::count());
        $this->assertSame('V', Transaction::firstOrFail()->transaction_status);
    }
}
