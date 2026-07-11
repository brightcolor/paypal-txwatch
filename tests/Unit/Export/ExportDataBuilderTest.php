<?php

namespace Tests\Unit\Export;

use App\Models\Event;
use App\Models\ExportTemplate;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Services\Export\ExportColumns;
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

    public function test_bestellnummer_and_event_columns_are_parsed_from_custom_field(): void
    {
        $this->makeTransaction(['custom_field' => 'Order GAG-WISMAR-2026-SC3HR']);

        $result = (new ExportDataBuilder())->build(
            Transaction::query(),
            null,
            ['columns' => ['event_ref', 'custom_field']],
        );

        $row = $result['groups'][0]['rows'][0];
        $this->assertSame('GAG-WISMAR-2026', $row['event_ref']);
        $this->assertSame('SC3HR', $row['custom_field']);

        $this->assertSame('Event', $result['column_labels'][0]);
        $this->assertSame('Bestellnummer', $result['column_labels'][1]);
    }

    public function test_vat_is_computed_per_row_from_the_gross_inclusive_amount(): void
    {
        // Gross is VAT-inclusive: at 19%, a gross of 119.00 contains 19.00 VAT
        // and 100.00 net.
        $this->makeTransaction(['gross_amount' => 119.00]);

        $result = (new ExportDataBuilder())->build(
            Transaction::query(),
            null,
            ['columns' => ['gross', 'vat', 'net_excl_vat'], 'vat_rate' => 19],
        );

        $row = $result['groups'][0]['rows'][0];
        $this->assertSame(19.0, $row['vat']);
        $this->assertSame(100.0, $row['net_excl_vat']);
    }

    public function test_vat_rate_is_configurable_per_export(): void
    {
        $this->makeTransaction(['gross_amount' => 107.00]);

        $result = (new ExportDataBuilder())->build(
            Transaction::query(),
            null,
            ['columns' => ['gross', 'vat'], 'vat_rate' => 7],
        );

        $this->assertSame(7.0, $result['groups'][0]['rows'][0]['vat']);
        $this->assertSame(7.0, $result['grand_total']['vat']);
        $this->assertSame('MwSt', $result['column_labels'][array_search('vat', $result['columns'], true)]);
    }

    public function test_real_pretix_tax_is_preferred_over_the_flat_rate(): void
    {
        $connection = \App\Models\PretixConnection::create([
            'name' => 'V', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'v', 'api_token' => 'x',
        ]);
        // Mixed 19%/7% order: real tax 10.00 on a 119.00 total - the 19% flat
        // formula would wrongly yield 19.00.
        $order = \App\Models\PretixOrder::create([
            'pretix_connection_id' => $connection->id, 'event_slug' => 's', 'order_code' => 'X1',
            'total' => 119.00, 'tax_total' => 10.00, 'url' => 'https://x/', 'raw_payload' => [],
        ]);

        $this->makeTransaction(['gross_amount' => 119.00, 'pretix_order_id' => $order->id]);
        $this->makeTransaction(['gross_amount' => 119.00]); // no pretix link -> flat rate

        $result = (new ExportDataBuilder())->build(
            Transaction::query()->orderBy('id'),
            null,
            ['columns' => ['gross', 'vat', 'net_excl_vat'], 'vat_rate' => 19],
        );

        [$linked, $unlinked] = $result['groups'][0]['rows'];
        $this->assertSame(10.0, $linked['vat']);
        $this->assertSame(109.0, $linked['net_excl_vat']);
        $this->assertSame(19.0, $unlinked['vat']);
        $this->assertSame(29.0, $result['grand_total']['vat']); // 10 real + 19 flat
    }

    public function test_partial_refund_carries_proportional_pretix_tax(): void
    {
        $connection = \App\Models\PretixConnection::create([
            'name' => 'V', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'v', 'api_token' => 'x',
        ]);
        $order = \App\Models\PretixOrder::create([
            'pretix_connection_id' => $connection->id, 'event_slug' => 's', 'order_code' => 'X2',
            'total' => 100.00, 'tax_total' => 7.00, 'url' => 'https://x/', 'raw_payload' => [],
        ]);

        $refund = $this->makeTransaction(['gross_amount' => -50.00, 'pretix_order_id' => $order->id]);

        // Half the order refunded -> half the real tax, negative.
        $this->assertSame(-3.5, $refund->vatAmount(19.0));
    }

    public function test_vat_totals_are_summed_and_gross_equals_net_plus_vat(): void
    {
        $this->makeTransaction(['gross_amount' => 119.00]);
        $this->makeTransaction(['gross_amount' => 238.00]);

        $result = (new ExportDataBuilder())->build(
            Transaction::query(),
            null,
            ['columns' => ['gross', 'vat', 'net_excl_vat'], 'vat_rate' => 19],
        );

        $total = $result['grand_total'];
        $this->assertSame(357.0, $total['gross']);
        $this->assertSame(57.0, $total['vat']); // 19 + 38
        $this->assertSame(300.0, $total['net_excl_vat']);
        $this->assertSame($total['gross'], round($total['net_excl_vat'] + $total['vat'], 2));
    }

    public function test_format_rate_drops_trailing_zeros(): void
    {
        $this->assertSame('19', ExportColumns::formatRate(19.0));
        $this->assertSame('7', ExportColumns::formatRate(7.0));
        $this->assertSame('7,5', ExportColumns::formatRate(7.5));
        $this->assertSame('0', ExportColumns::formatRate(0.0));
    }

    public function test_zero_vat_rate_yields_zero_vat(): void
    {
        $this->makeTransaction(['gross_amount' => 100.00]);

        $result = (new ExportDataBuilder())->build(
            Transaction::query(),
            null,
            ['columns' => ['gross', 'vat', 'net_excl_vat'], 'vat_rate' => 0],
        );

        $row = $result['groups'][0]['rows'][0];
        $this->assertSame(0.0, $row['vat']);
        $this->assertSame(100.0, $row['net_excl_vat']);
    }

    public function test_default_vat_rate_is_19_percent(): void
    {
        $this->makeTransaction(['gross_amount' => 119.00]);

        $result = (new ExportDataBuilder())->build(
            Transaction::query(),
            null,
            ['columns' => ['gross', 'vat']],
        );

        $this->assertSame(19.0, $result['vat_rate']);
        $this->assertSame(19.0, $result['groups'][0]['rows'][0]['vat']);
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
