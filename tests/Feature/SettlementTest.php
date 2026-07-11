<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\PaypalAccount;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Export\PdfRenderer;
use App\Services\Export\SettlementBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementTest extends TestCase
{
    use RefreshDatabase;

    private function tx(array $a): Transaction
    {
        static $i = 0;
        $i++;

        return Transaction::create(array_merge([
            'currency' => 'EUR', 'raw_payload' => [], 'raw_hash' => hash('sha256', 'h' . $i),
            'dedupe_key' => hash('sha256', 'k' . $i), 'imported_at' => now(), 'transaction_initiation_date' => now(),
        ], $a));
    }

    public function test_chargeback_is_classified_separately_from_a_refund(): void
    {
        $account = PaypalAccount::create(['name' => 'A', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        $refund = $this->tx(['paypal_account_id' => $account->id, 'transaction_id' => 'R', 'transaction_event_code' => 'T1107', 'gross_amount' => -10, 'net_amount' => -10]);
        $chargeback = $this->tx(['paypal_account_id' => $account->id, 'transaction_id' => 'C', 'transaction_event_code' => 'T1106', 'gross_amount' => -50, 'net_amount' => -50]);

        $this->assertFalse($refund->isChargeback());
        $this->assertTrue($chargeback->isChargeback());
        $this->assertSame('Rückbuchung/Chargeback', $chargeback->typeLabel());
    }

    public function test_event_settlement_persists_a_frozen_snapshot_and_renders(): void
    {
        $customer = Customer::create(['name' => 'SV Wismar']);
        $event = Event::create(['name' => 'Sportfest', 'customer_id' => $customer->id]);
        $account = PaypalAccount::create(['name' => 'A', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        $user = User::factory()->create();

        $this->tx(['event_id' => $event->id, 'paypal_account_id' => $account->id, 'transaction_id' => 'P1', 'transaction_event_code' => 'T0006', 'gross_amount' => 100, 'fee_amount' => -3, 'net_amount' => 97]);
        $this->tx(['event_id' => $event->id, 'transaction_id' => 'BT1', 'instrument_type' => 'pretix', 'gross_amount' => 50, 'fee_amount' => -0.20, 'net_amount' => 49.80]);
        $this->tx(['event_id' => $event->id, 'paypal_account_id' => $account->id, 'transaction_id' => 'CB1', 'transaction_event_code' => 'T1106', 'gross_amount' => -20, 'net_amount' => -20]);

        $builder = new SettlementBuilder();
        $settlement = $builder->persist($builder->build($event, 19.0), $user);

        $this->assertSame(Settlement::STATUS_OPEN, $settlement->status);
        $this->assertSame('130.00', $settlement->gross);    // 100 + 50 - 20
        $this->assertSame('126.80', $settlement->payout);   // 97 + 49.80 - 20
        $this->assertContains('Rückbuchungen/Chargebacks', array_column($settlement->blocks, 'label'));

        // Snapshot is frozen: moving a transaction off the event later does not change it.
        Transaction::query()->where('transaction_id', 'P1')->update(['event_id' => null]);
        $this->assertSame('130.00', $settlement->fresh()->gross);

        // The PDF data shape reconstructs from the frozen snapshot (actual
        // Browsershot rendering needs Chromium and is verified on the server).
        $data = $settlement->fresh()->pdfData();
        $this->assertSame('130.00', number_format($data['totals']['amount'], 2, '.', ''));
        $this->assertSame($event->id, $data['event']->id);
        $view = view('exports.settlement', $data)->render();
        $this->assertStringContainsString('Auszahlungsbetrag', $view);
        $this->assertStringContainsString('Rückbuchungen/Chargebacks', $view);
    }

    public function test_customer_settlement_aggregates_events_with_a_per_event_breakdown(): void
    {
        $customer = Customer::create(['name' => 'SV Wismar']);
        $eventA = Event::create(['name' => 'Fest A', 'customer_id' => $customer->id]);
        $eventB = Event::create(['name' => 'Fest B', 'customer_id' => $customer->id]);
        $account = PaypalAccount::create(['name' => 'A', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        $user = User::factory()->create();

        $this->tx(['event_id' => $eventA->id, 'paypal_account_id' => $account->id, 'transaction_id' => 'A1', 'transaction_event_code' => 'T0006', 'gross_amount' => 100, 'net_amount' => 97]);
        $this->tx(['event_id' => $eventB->id, 'paypal_account_id' => $account->id, 'transaction_id' => 'B1', 'transaction_event_code' => 'T0006', 'gross_amount' => 40, 'net_amount' => 39]);

        $builder = new SettlementBuilder();
        $data = $builder->buildForCustomer($customer, 19.0);

        $this->assertCount(2, $data['events']);
        $this->assertSame(140.0, $data['totals']['amount']);

        $settlement = $builder->persist($data, $user);
        $this->assertNull($settlement->event_id);
        $this->assertSame($customer->id, $settlement->customer_id);
        $this->assertCount(2, $settlement->events);
    }

    public function test_mark_paid_transition(): void
    {
        $user = User::factory()->create();
        $event = Event::create(['name' => 'X']);
        $settlement = (new SettlementBuilder())->persist((new SettlementBuilder())->build($event), $user);

        $settlement->update(['status' => Settlement::STATUS_PAID, 'paid_at' => now(), 'paid_reference' => 'SEPA-123']);

        $this->assertTrue($settlement->fresh()->isPaid());
        $this->assertSame('SEPA-123', $settlement->fresh()->paid_reference);
    }
}
