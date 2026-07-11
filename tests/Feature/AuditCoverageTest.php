<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Customer;
use App\Models\MailSetting;
use App\Models\PaypalAccount;
use App\Models\Settlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_and_updating_a_paypal_account_is_audited(): void
    {
        $account = PaypalAccount::create(['name' => 'Haupt', 'mode' => 'live', 'client_id' => 'x', 'client_secret' => 'y']);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => PaypalAccount::class, 'subject_id' => $account->id, 'event' => 'created',
        ]);

        $account->update(['is_active' => false]);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => PaypalAccount::class, 'subject_id' => $account->id, 'event' => 'updated',
        ]);
    }

    public function test_settlement_status_change_is_audited(): void
    {
        $s = Settlement::create([
            'title' => 'X', 'status' => Settlement::STATUS_OPEN, 'blocks' => [], 'events' => [],
            'gross' => 0, 'fees' => 0, 'payout' => 0, 'vat' => 0, 'net_excl_vat' => 0, 'tx_count' => 0,
        ]);
        $s->update(['status' => Settlement::STATUS_PAID, 'paid_at' => now()]);

        $entry = AuditLogEntry::where('subject_type', Settlement::class)->where('event', 'updated')->first();
        $this->assertNotNull($entry);
        $this->assertSame(Settlement::STATUS_PAID, $entry->properties['attributes']['status']);
    }

    public function test_secrets_are_never_written_to_the_audit_log(): void
    {
        $account = PaypalAccount::create(['name' => 'S', 'mode' => 'live', 'client_id' => 'pub', 'client_secret' => 'TOP-SECRET']);

        $entry = AuditLogEntry::where('subject_type', PaypalAccount::class)->where('subject_id', $account->id)->first();
        $this->assertArrayNotHasKey('client_secret', $entry->properties['attributes'] ?? []);

        // Mail password must never appear either.
        MailSetting::current()->update(['host' => 'smtp.x', 'password' => 'PW-SECRET']);
        $mailEntry = AuditLogEntry::where('subject_type', MailSetting::class)->latest('id')->first();
        $this->assertArrayNotHasKey('password', $mailEntry->properties['attributes'] ?? []);
    }

    public function test_customer_creation_is_audited_with_german_description(): void
    {
        $customer = Customer::create(['name' => 'Verein', 'is_active' => true]);

        $entry = AuditLogEntry::where('subject_type', Customer::class)->where('subject_id', $customer->id)->first();
        $this->assertNotNull($entry);
        $this->assertStringContainsString('angelegt', $entry->description);
    }
}
