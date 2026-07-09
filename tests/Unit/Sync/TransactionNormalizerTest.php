<?php

namespace Tests\Unit\Sync;

use App\Models\PaypalAccount;
use App\Services\Sync\TransactionNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionNormalizerTest extends TestCase
{
    use RefreshDatabase;

    private function account(): PaypalAccount
    {
        return PaypalAccount::create([
            'name' => 'Acc',
            'mode' => 'sandbox',
            'client_id' => 'id',
            'client_secret' => 'secret',
            'default_currency' => 'USD',
        ]);
    }

    public function test_it_extracts_all_normalized_fields_from_a_raw_record(): void
    {
        $raw = [
            'transaction_info' => [
                'transaction_id' => 'TXN123',
                'transaction_event_code' => 'T0000',
                'transaction_status' => 'S',
                'transaction_initiation_date' => '2026-06-01T10:00:00+0000',
                'transaction_updated_date' => '2026-06-01T10:05:00+0000',
                'transaction_amount' => ['currency_code' => 'EUR', 'value' => '100.00'],
                'fee_amount' => ['currency_code' => 'EUR', 'value' => '-3.50'],
                'invoice_id' => 'INV-1',
                'custom_field' => 'EVENT-42',
                'paypal_reference_id' => 'REF1',
                'paypal_reference_id_type' => 'TXN',
                'payment_method_type' => 'CREDITCARD',
                'instrument_type' => 'VISA',
                'protection_eligibility' => 'ELIGIBLE',
                'transaction_subject' => 'Ticket',
                'transaction_note' => 'Danke',
            ],
            'payer_info' => [
                'email_address' => 'payer@example.com',
                'payer_name' => ['given_name' => 'Erika', 'surname' => 'Musterfrau'],
                'country_code' => 'DE',
            ],
            'cart_info' => [
                'item_details' => [['item_name' => 'Ticket', 'item_quantity' => '1']],
            ],
        ];

        $normalized = (new TransactionNormalizer())->normalize($this->account(), $raw);

        $this->assertSame('TXN123', $normalized['transaction_id']);
        $this->assertSame('T0000', $normalized['transaction_event_code']);
        $this->assertSame('S', $normalized['transaction_status']);
        $this->assertSame(100.0, $normalized['gross_amount']);
        $this->assertSame(-3.5, $normalized['fee_amount']);
        $this->assertSame(96.5, $normalized['net_amount']);
        $this->assertSame('EUR', $normalized['currency']);
        $this->assertSame('INV-1', $normalized['invoice_id']);
        $this->assertSame('EVENT-42', $normalized['custom_field']);
        $this->assertSame('Erika Musterfrau', $normalized['payer_name']);
        $this->assertSame('payer@example.com', $normalized['payer_email']);
        $this->assertSame('DE', $normalized['payer_country_code']);
        $this->assertSame('CREDITCARD', $normalized['payment_method_type']);
        $this->assertSame('VISA', $normalized['instrument_type']);
        $this->assertSame('Ticket', $normalized['subject']);
        $this->assertSame('Danke', $normalized['note']);
        $this->assertCount(1, $normalized['item_info']);
        $this->assertSame(64, strlen($normalized['raw_hash']));
        $this->assertSame(64, strlen($normalized['dedupe_key']));
    }

    public function test_dedupe_key_is_stable_for_identical_input_and_changes_when_updated_date_changes(): void
    {
        $account = $this->account();
        $normalizer = new TransactionNormalizer();

        $raw = [
            'transaction_info' => [
                'transaction_id' => 'TXN1',
                'transaction_event_code' => 'T0000',
                'transaction_initiation_date' => '2026-06-01T10:00:00+0000',
                'transaction_updated_date' => '2026-06-01T10:00:00+0000',
                'transaction_amount' => ['currency_code' => 'EUR', 'value' => '10.00'],
            ],
        ];

        $first = $normalizer->normalize($account, $raw);
        $second = $normalizer->normalize($account, $raw);
        $this->assertSame($first['dedupe_key'], $second['dedupe_key']);

        $raw['transaction_info']['transaction_updated_date'] = '2026-06-02T00:00:00+0000';
        $third = $normalizer->normalize($account, $raw);
        $this->assertNotSame($first['dedupe_key'], $third['dedupe_key']);
    }

    public function test_falls_back_to_account_default_currency_when_missing(): void
    {
        $account = $this->account();
        $raw = ['transaction_info' => ['transaction_id' => 'TXN1']];

        $normalized = (new TransactionNormalizer())->normalize($account, $raw);

        $this->assertSame('USD', $normalized['currency']);
        $this->assertNull($normalized['gross_amount']);
    }
}
