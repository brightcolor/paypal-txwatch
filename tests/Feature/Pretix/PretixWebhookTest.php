<?php

namespace Tests\Feature\Pretix;

use App\Jobs\ImportPretixOrdersJob;
use App\Models\PretixConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PretixWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function connection(array $overrides = []): PretixConnection
    {
        return PretixConnection::create(array_merge([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein',
            'api_token' => 'tok', 'is_active' => true, 'sync_enabled' => true,
        ], $overrides));
    }

    public function test_valid_secret_queues_an_import(): void
    {
        Queue::fake();
        $connection = $this->connection();

        $this->postJson("/webhooks/pretix/{$connection->webhook_secret}")
            ->assertOk()
            ->assertJson(['status' => 'queued']);

        Queue::assertPushed(ImportPretixOrdersJob::class);
    }

    public function test_unknown_secret_is_ignored_without_dispatch(): void
    {
        Queue::fake();
        $this->connection();

        $this->postJson('/webhooks/pretix/nonsense-secret')
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        Queue::assertNothingPushed();
    }

    public function test_disabled_sync_is_ignored_without_dispatch(): void
    {
        Queue::fake();
        $connection = $this->connection(['sync_enabled' => false]);

        $this->postJson("/webhooks/pretix/{$connection->webhook_secret}")
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        Queue::assertNothingPushed();
    }

    public function test_each_connection_gets_a_unique_webhook_secret(): void
    {
        $a = $this->connection();
        $b = $this->connection(['name' => 'Zweiter']);

        $this->assertNotEmpty($a->webhook_secret);
        $this->assertNotSame($a->webhook_secret, $b->webhook_secret);
    }
}
