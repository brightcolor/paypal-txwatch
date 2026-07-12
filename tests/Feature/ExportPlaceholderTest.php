<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Services\Export\ExportDataBuilder;
use App\Services\Export\ExportPlaceholders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExportPlaceholderTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_replaces_known_keys_and_blanks_unknown(): void
    {
        $ctx = ['event.name' => 'Sommerfest', 'period.to' => '31.07.2026'];

        $this->assertSame(
            'Abrechnung Sommerfest bis 31.07.2026',
            ExportPlaceholders::resolve('Abrechnung {{ event.name }} bis {{ period.to }}', $ctx),
        );
        // Case-insensitive key, no spaces, and unknown -> empty.
        $this->assertSame('X   Y', ExportPlaceholders::resolve('X {{EVENT.unknown}} {{ nope }} Y', $ctx));
        $this->assertNull(ExportPlaceholders::resolve(null, $ctx));
    }

    public function test_context_exposes_event_and_customer_data(): void
    {
        $customer = Customer::create(['name' => 'FC Anker', 'contact_email' => 'v@x.de', 'is_active' => true]);
        $event = Event::create([
            'name' => 'Testspiel', 'customer_id' => $customer->id, 'venue' => 'Stadion',
            'event_date' => '2026-08-01', 'is_active' => true,
        ]);

        $ctx = ExportPlaceholders::context(
            $event,
            ['from' => Carbon::parse('2026-06-01'), 'to' => Carbon::parse('2026-07-31')],
            42,
            Carbon::parse('2026-07-12 09:00'),
            19.0,
        );

        $this->assertSame('Testspiel', $ctx['event.name']);
        $this->assertSame('01.08.2026', $ctx['event.date']);
        $this->assertSame('Stadion', $ctx['event.venue']);
        $this->assertSame('FC Anker', $ctx['customer.name']);
        $this->assertSame('v@x.de', $ctx['customer.email']);
        $this->assertSame('31.07.2026', $ctx['period.to']);
        $this->assertSame('42', $ctx['count']);
        $this->assertSame('12.07.2026', $ctx['date']);
        $this->assertSame('19 %', $ctx['vat_rate']);
    }

    public function test_filename_sanitizes_and_appends_extension(): void
    {
        $ctx = ['event.name' => 'FC Anker / Hansa', 'period.to' => '31.07.2026'];

        $name = ExportPlaceholders::filename('Abrechnung {{ event.name }} {{ period.to }}', $ctx, 'pdf', 'fallback');
        $this->assertSame('Abrechnung FC Anker Hansa 31.07.2026.pdf', $name);

        // Empty pattern -> fallback + extension.
        $this->assertSame('fallback.csv', ExportPlaceholders::filename(null, $ctx, 'csv', 'fallback'));
        // Pattern that resolves to nothing usable -> fallback.
        $this->assertSame('fallback.xlsx', ExportPlaceholders::filename('{{ nope }}', $ctx, 'xlsx', 'fallback'));
    }

    public function test_builder_resolves_placeholders_in_texts_and_exposes_context(): void
    {
        $event = Event::create(['name' => 'Cup', 'is_active' => true]);
        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        Transaction::create([
            'paypal_account_id' => $account->id, 'event_id' => $event->id, 'transaction_id' => 'T1',
            'transaction_event_code' => 'T0006', 'gross_amount' => 10, 'net_amount' => 10, 'currency' => 'EUR',
            'transaction_initiation_date' => now(),
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'a'), 'dedupe_key' => hash('sha256', 'b'), 'imported_at' => now(),
        ]);

        $built = app(ExportDataBuilder::class)->build(
            Transaction::query(),
            null,
            ['vat_rate' => 19.0, 'event' => $event, 'title' => 'Bericht {{ event.name }}', 'description' => 'Für {{ event.display_name }}'],
        );

        $this->assertSame('Bericht Cup', $built['title']);
        $this->assertSame('Für Cup', $built['description']);
        $this->assertSame('Cup', $built['placeholder_context']['event.name']);
    }
}
