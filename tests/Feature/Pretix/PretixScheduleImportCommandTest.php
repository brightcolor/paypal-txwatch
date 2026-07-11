<?php

namespace Tests\Feature\Pretix;

use App\Jobs\ImportPretixOrdersJob;
use App\Models\PretixConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PretixScheduleImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_only_for_active_connections_with_sync_enabled(): void
    {
        Queue::fake();

        $enabled = PretixConnection::create([
            'name' => 'A', 'base_url' => 'https://a.example', 'organizer_slug' => 'a', 'api_token' => 'x',
            'is_active' => true, 'sync_enabled' => true,
        ]);
        PretixConnection::create([
            'name' => 'B', 'base_url' => 'https://b.example', 'organizer_slug' => 'b', 'api_token' => 'x',
            'is_active' => true, 'sync_enabled' => false,
        ]);
        PretixConnection::create([
            'name' => 'C', 'base_url' => 'https://c.example', 'organizer_slug' => 'c', 'api_token' => 'x',
            'is_active' => false, 'sync_enabled' => true,
        ]);

        $this->artisan('pretix:schedule-import')->assertSuccessful();

        Queue::assertPushed(ImportPretixOrdersJob::class, 1);
        Queue::assertPushed(fn (ImportPretixOrdersJob $job) => $job->pretixConnectionId === $enabled->id);
    }

    public function test_scheduler_has_the_import_registered(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $this->assertTrue(
            $events->contains(fn ($e) => str_contains($e->command ?? '', 'pretix:schedule-import')),
            'pretix:schedule-import ist nicht im Scheduler registriert',
        );
    }
}
