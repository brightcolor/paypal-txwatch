<?php

namespace Tests\Unit\Export;

use App\Models\Event;
use App\Models\ExportTemplate;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Services\Export\ExportDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExportDataBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function makeTransaction(array $overrides = []): Transaction
    {
        $account = PaypalAccount::firstOrCreate(
            ['name' => 'Acc'],
            ['mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y'],
        );

        return Transaction::create(array_merge([
            'paypal_account_id' => $account->id,
            'transaction_id' => 'TXN-' . uniqid(),
            'transaction_status' => 'S',
            'transaction_initiation_date' => Carbon::parse('2026-06-01 10:00:00'),
            'gross_amount' => 100,
            'fee_amount' => -5,
            'net_amount' => 95,
            'currency' => 'EUR',
            'payer_name' => 'Max Mustermann',
            'payer_email' => 'max@example.com',
            'raw_payload' => ['transaction_info' => []],
            'raw_hash' => hash('sha256', uniqid()),
            'dedupe_key' => hash('sha256', uniqid()),
            'imported_at' => now(),
        ], $overrides));
    }

    public function test_it_builds_a_single_group_with_grand_total_by_default(): void
    {
        $this->makeTransaction(['gross_amount' => 100, 'fee_amount' => -5, 'net_amount' => 95]);
        $this->makeTransaction(['gross_amount' => 50, 'fee_amount' => -2, 'net_amount' => 48]);

        $result = (new ExportDataBuilder())->build(Transaction::query(), null);

        $this->assertCount(1, $result['groups']);
        $this->assertCount(2, $result['groups'][0]['rows']);
        $this->assertSame(150.0, $result['grand_total']['gross']);
        $this->assertSame(-7.0, $result['grand_total']['fee']);
        $this->assertSame(143.0, $result['grand_total']['net']);
        $this->assertSame(2, $result['grand_total']['count']);
    }

    public function test_it_groups_by_event_with_per_group_sums(): void
    {
        $eventA = Event::create(['name' => 'Event A']);
        $eventB = Event::create(['name' => 'Event B']);

        $this->makeTransaction(['event_id' => $eventA->id, 'gross_amount' => 100]);
        $this->makeTransaction(['event_id' => $eventA->id, 'gross_amount' => 20]);
        $this->makeTransaction(['event_id' => $eventB->id, 'gross_amount' => 30]);

        $result = (new ExportDataBuilder())->build(
            Transaction::query(),
            null,
            ['group_by' => 'event'],
        );

        $labels = collect($result['groups'])->pluck('label')->all();
        $this->assertContains('Event A', $labels);
        $this->assertContains('Event B', $labels);

        $groupA = collect($result['groups'])->firstWhere('label', 'Event A');
        $this->assertSame(120.0, $groupA['sum']['gross']);
        $this->assertCount(2, $groupA['rows']);
    }

    public function test_customer_mode_hides_internal_only_columns(): void
    {
        $this->makeTransaction();

        $result = (new ExportDataBuilder())->build(
            Transaction::query(),
            null,
            ['columns' => ['date', 'transaction_id', 'paypal_account', 't_code'], 'mode' => ExportTemplate::MODE_CUSTOMER],
        );

        $this->assertSame(['date', 'transaction_id'], $result['columns']);

        $result = (new ExportDataBuilder())->build(
            Transaction::query(),
            null,
            ['columns' => ['date', 'transaction_id', 'paypal_account', 't_code'], 'mode' => ExportTemplate::MODE_INTERNAL],
        );

        $this->assertSame(['date', 'transaction_id', 'paypal_account', 't_code'], $result['columns']);
    }

    public function test_mask_pii_masks_name_and_email(): void
    {
        $this->makeTransaction(['payer_name' => 'Max Mustermann', 'payer_email' => 'max@example.com']);

        $result = (new ExportDataBuilder())->build(
            Transaction::query(),
            null,
            ['columns' => ['name', 'email'], 'mask_pii' => true],
        );

        $row = $result['groups'][0]['rows'][0];
        $this->assertStringStartsWith('Ma', $row['name']);
        $this->assertStringContainsString('*', $row['name']);
        $this->assertStringStartsWith('ma', $row['email']);
        $this->assertStringContainsString('*', $row['email']);
    }

    public function test_it_uses_export_template_configuration(): void
    {
        $this->makeTransaction(['gross_amount' => 10]);

        $template = ExportTemplate::create([
            'name' => 'Kunde Standard',
            'columns' => ['date', 'gross'],
            'mode' => ExportTemplate::MODE_CUSTOMER,
            'title' => 'Vorlagen-Titel',
        ]);

        $result = (new ExportDataBuilder())->build(Transaction::query(), $template);

        $this->assertSame('Vorlagen-Titel', $result['title']);
        $this->assertSame(['date', 'gross'], $result['columns']);
    }
}
