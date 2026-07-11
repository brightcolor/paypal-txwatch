<?php

namespace App\Http\Controllers;

use App\Jobs\ImportPretixOrdersJob;
use App\Models\PretixConnection;
use Illuminate\Http\JsonResponse;

/**
 * Receives pretix webhooks (order placed/paid/changed/…) and triggers an
 * incremental import so the reconciliation is near-real-time instead of
 * waiting for the 30-minute schedule. Authorized by the opaque secret in the
 * URL (pretix has no HMAC signature by default). The dispatch is delayed a
 * minute so a burst of order events coalesces into one import run (the job's
 * own guard also collapses overlapping runs).
 */
class PretixWebhookController extends Controller
{
    public function __invoke(string $secret): JsonResponse
    {
        $connection = PretixConnection::query()
            ->where('webhook_secret', $secret)
            ->where('is_active', true)
            ->first();

        // Always answer 200 so pretix does not disable the webhook or retry a
        // storm; an unknown/inactive secret is simply ignored.
        if (! $connection || ! $connection->sync_enabled) {
            return response()->json(['status' => 'ignored']);
        }

        ImportPretixOrdersJob::dispatch($connection->id)->delay(now()->addMinute());

        return response()->json(['status' => 'queued']);
    }
}
