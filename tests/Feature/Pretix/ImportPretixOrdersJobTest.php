<?php

namespace Tests\Feature\Pretix;

use App\Jobs\ImportPretixOrdersJob;
use App\Models\PaypalAccount;
use App\Models\PretixConnection;
use App\Models\PretixImportRun;
use App\Models\PretixOrder;
use App\Models\Transaction;
use App\Services\Pretix\PretixOrderImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportPretixOrdersJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_imports_and_records_a_summary_and_clears_running_flag(): void
    {
        Http::fake([
            '*/events/sportfest/orders/*' => Http::response([
                'results' => [['code' => 'ABCDE', 'status' => 'p', 'total' => '50.00', 'currency' => 'EUR', 'payments' => [['provider' => 'paypal']]]],
                'next' => null,
            ]),
            '*/events/*' => Http::response(['results' => [['slug' => 'sportfest', 'name' => 'Sportfest']], 'next' => null]),
        ]);

        $connection = PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein',
            'api_token' => 'tok', 'import_running' => true,
        ]);

        $account = PaypalAccount::create(['name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'x', 'client_secret' => 'y']);
        $tx = Transaction::create([
            'paypal_account_id' => $account->id, 'transaction_id' => 'T1', 'transaction_event_code' => 'T0006',
            'custom_field' => 'Order SPORTFEST-ABCDE', 'gross_amount' => 50.00, 'currency' => 'EUR',
            'raw_payload' => [], 'raw_hash' => hash('sha256', 'a'), 'dedupe_key' => hash('sha256', 'b'), 'imported_at' => now(),
        ]);

        (new ImportPretixOrdersJob($connection->id))->handle(app(PretixOrderImporter::class));

        $connection->refresh();
        $this->assertFalse($connection->import_running);
        $this->assertStringContainsString('abgeglichen 1', $connection->last_import_summary);
        $this->assertNotNull($connection->last_successful_sync_at);
        $this->assertSame(1, PretixOrder::count());
        $this->assertSame(Transaction::RECONCILIATION_MATCHED, $tx->fresh()->reconciliation_status);

        // A run with a progress log was recorded.
        $run = PretixImportRun::latest('id')->first();
        $this->assertSame(PretixImportRun::STATUS_SUCCESS, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(1, $run->events_total);
        $this->assertSame(1, $run->orders_imported);
        $this->assertNotEmpty($run->log);
        $messages = collect($run->log)->pluck('m')->implode(' | ');
        $this->assertStringContainsString('Abgleich fertig', $messages);
    }

    public function test_a_second_run_is_skipped_while_one_is_active_but_allowed_after_it_finished(): void
    {
        Http::fake([
            '*/events/sportfest/orders/*' => Http::response(['results' => [], 'next' => null]),
            '*/events/*' => Http::response(['results' => [['slug' => 'sportfest', 'name' => 'S']], 'next' => null]),
        ]);

        $connection = PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein', 'api_token' => 'tok',
        ]);

        // Active run younger than the timeout -> skip, no new run row.
        PretixImportRun::create([
            'pretix_connection_id' => $connection->id,
            'status' => PretixImportRun::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
            'log' => [],
        ]);
        (new ImportPretixOrdersJob($connection->id))->handle(app(PretixOrderImporter::class));
        $this->assertSame(1, PretixImportRun::count());

        // Stale "running" run older than the timeout must NOT block (self-healing).
        PretixImportRun::query()->update(['started_at' => now()->subHours(2)]);
        (new ImportPretixOrdersJob($connection->id))->handle(app(PretixOrderImporter::class));
        $this->assertSame(2, PretixImportRun::count());
    }

    public function test_failed_import_clears_running_flag_and_records_error(): void
    {
        Http::fake([
            '*/events/*' => Http::response(['detail' => 'boom'], 500),
        ]);

        $connection = PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein',
            'api_token' => 'tok', 'import_running' => true,
        ]);

        try {
            (new ImportPretixOrdersJob($connection->id))->handle(app(PretixOrderImporter::class));
        } catch (\Throwable $e) {
            // expected - the importer rethrows so the queue can mark the job failed
        }

        $connection->refresh();
        $this->assertFalse($connection->import_running);
        $this->assertNotNull($connection->last_error);
    }
}
