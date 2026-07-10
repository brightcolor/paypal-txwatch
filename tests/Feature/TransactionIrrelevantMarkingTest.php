<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reporting\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TransactionIrrelevantMarkingTest extends TestCase
{
    use RefreshDatabase;

    private function account(): PaypalAccount
    {
        return PaypalAccount::create([
            'name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y', 'default_currency' => 'EUR',
        ]);
    }

    private function transaction(array $overrides = []): Transaction
    {
        static $i = 0;
        $i++;

        return Transaction::create(array_merge([
            'paypal_account_id' => $this->account()->id,
            'transaction_id' => 'TXN' . $i,
            'transaction_initiation_date' => Carbon::parse('2026-06-01'),
            'gross_amount' => 100,
            'fee_amount' => -3,
            'net_amount' => 97,
            'currency' => 'EUR',
            'raw_payload' => [],
            'raw_hash' => hash('sha256', 'h' . $i),
            'dedupe_key' => hash('sha256', 'k' . $i),
            'imported_at' => now(),
        ], $overrides));
    }

    public function test_marking_irrelevant_sets_fields_and_writes_an_audit_log_entry(): void
    {
        $user = User::factory()->create();
        $tx = $this->transaction();

        $tx->markIrrelevant($user, 'Testbuchung, kein echter Verkauf');

        $this->assertTrue($tx->fresh()->isIrrelevant());
        $this->assertSame('Testbuchung, kein echter Verkauf', $tx->fresh()->irrelevant_reason);
        $this->assertSame($user->id, $tx->fresh()->irrelevant_marked_by_user_id);

        $entry = AuditLogEntry::query()->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame($user->id, $entry->causer_id);
        $this->assertSame($tx->id, $entry->subject_id);
        $this->assertSame('Testbuchung, kein echter Verkauf', $entry->properties['reason']);
    }

    public function test_marking_relevant_again_reverses_the_flag_and_is_also_audited(): void
    {
        $user = User::factory()->create();
        $tx = $this->transaction();
        $tx->markIrrelevant($user, 'versehentlich');
        $tx->markRelevant($user, 'doch relevant');

        $this->assertFalse($tx->fresh()->isIrrelevant());
        $this->assertNull($tx->fresh()->marked_irrelevant_at);
        $this->assertNull($tx->fresh()->irrelevant_marked_by_user_id);
        $this->assertSame(2, AuditLogEntry::query()->count());
    }

    public function test_irrelevant_transactions_are_excluded_from_reports(): void
    {
        $user = User::factory()->create();
        $this->transaction(['gross_amount' => 100]);
        $irrelevant = $this->transaction(['gross_amount' => 5000]);
        $irrelevant->markIrrelevant($user, 'Ausreißer');

        $ratio = (new ReportService())->eventAssignmentRatio();

        $this->assertSame(1, $ratio['total']);
    }

    public function test_transactions_can_never_be_deleted(): void
    {
        $tx = $this->transaction();

        $this->expectException(\RuntimeException::class);
        $tx->delete();
    }

    public function test_transactions_can_never_be_force_deleted(): void
    {
        $tx = $this->transaction();

        $this->expectException(\RuntimeException::class);
        $tx->forceDelete();
    }

    public function test_audit_log_entries_can_never_be_deleted(): void
    {
        $user = User::factory()->create();
        $tx = $this->transaction();
        $tx->markIrrelevant($user, 'grund');

        $entry = AuditLogEntry::query()->latest('id')->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $entry->delete();
    }
}
