<?php

namespace Tests\Feature;

use App\Models\BrandSetting;
use App\Models\ExportTemplate;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Services\Export\ExportDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_template_can_be_default(): void
    {
        $a = ExportTemplate::create(['name' => 'A', 'columns' => ExportTemplate::DEFAULT_COLUMNS, 'is_default' => true]);
        $b = ExportTemplate::create(['name' => 'B', 'columns' => ExportTemplate::DEFAULT_COLUMNS, 'is_default' => true]);

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
        $this->assertSame($b->id, ExportTemplate::defaultTemplate()->id);
    }

    public function test_accent_color_flows_from_template_into_the_pdf(): void
    {
        $template = ExportTemplate::create(['name' => 'Rot', 'columns' => ExportTemplate::DEFAULT_COLUMNS, 'accent_color' => '#aa0000']);

        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'transaction_id' => 'T1', 'transaction_event_code' => 'T0006',
            'gross_amount' => 10, 'net_amount' => 10, 'currency' => 'EUR', 'transaction_initiation_date' => now(),
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'a'), 'dedupe_key' => hash('sha256', 'b'), 'imported_at' => now(),
        ]);

        $built = app(ExportDataBuilder::class)->build(Transaction::query(), $template, ['vat_rate' => 19.0]);
        $this->assertSame('#aa0000', $built['accent_color']);

        $html = view('exports.pdf', $built)->render();
        $this->assertStringContainsString('--accent: #aa0000', $html);

        // Ad-hoc override wins over the template.
        $built = app(ExportDataBuilder::class)->build(Transaction::query(), $template, ['vat_rate' => 19.0, 'accent_color' => '#00aa00']);
        $this->assertSame('#00aa00', $built['accent_color']);
    }

    public function test_default_accent_is_the_house_blue(): void
    {
        $built = app(ExportDataBuilder::class)->build(Transaction::query(), null, ['vat_rate' => 19.0]);
        $this->assertSame('#1d4ed8', $built['accent_color']);
    }

    public function test_pdf_repeats_the_document_header_via_page_frame_thead(): void
    {
        $built = app(ExportDataBuilder::class)->build(Transaction::query(), null, ['vat_rate' => 19.0]);
        $html = view('exports.pdf', $built)->render();

        $this->assertStringContainsString('page-frame', $html);
        $this->assertStringContainsString('table-header-group', $html);
    }

    public function test_brand_setting_is_null_safe_and_claim_renders_on_cover(): void
    {
        $this->assertNull(BrandSetting::current()->logoAbsolutePath());
        $this->assertNull(BrandSetting::current()->logoDataUri());

        BrandSetting::current()->update(['claim' => 'Bericht & Ticketing: HSP']);

        $event = \App\Models\Event::create(['name' => 'Cup', 'is_active' => true]);
        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'event_id' => $event->id, 'transaction_id' => 'T1',
            'transaction_event_code' => 'T0006', 'gross_amount' => 10, 'net_amount' => 10, 'currency' => 'EUR',
            'transaction_initiation_date' => now(),
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'a'), 'dedupe_key' => hash('sha256', 'b'), 'imported_at' => now(),
        ]);

        $built = app(ExportDataBuilder::class)->build(Transaction::query(), null, ['vat_rate' => 19.0]);
        $built['event'] = $event;

        $html = view('exports.pdf', $built)->render();
        $this->assertStringContainsString('Bericht &amp; Ticketing: HSP', $html);
    }
}
