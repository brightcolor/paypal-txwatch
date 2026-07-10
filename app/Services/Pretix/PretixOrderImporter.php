<?php

namespace App\Services\Pretix;

use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Models\Transaction;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Imports pretix orders as reference data and reconciles them against the
 * (authoritative) PayPal transactions: PayPal stays the source of truth, but
 * every order is linked so the two sources can be cross-checked for
 * plausibility and the order number can deep-link into pretix.
 */
class PretixOrderImporter
{
    public function __construct(private readonly PretixReconciler $reconciler)
    {
    }

    /**
     * @return array{events: int, orders: int, matched: int, mismatch: int, unmatched: int}
     */
    public function import(PretixConnection $connection): array
    {
        $client = new PretixClient($connection);
        $orderCount = 0;

        try {
            $events = $client->events();

            foreach ($events as $event) {
                $slug = $event['slug'];

                foreach ($client->ordersForEvent($slug) as $raw) {
                    $this->upsertOrder($connection, $client, $slug, $raw);
                    $orderCount++;
                }
            }

            $reconciliation = $this->reconciler->reconcile($connection);

            $connection->forceFill([
                'last_synced_at' => now(),
                'last_successful_sync_at' => now(),
                'last_error' => null,
            ])->save();

            return array_merge(['events' => count($events), 'orders' => $orderCount], $reconciliation);
        } catch (Throwable $e) {
            $connection->forceFill([
                'last_synced_at' => now(),
                'last_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    private function upsertOrder(PretixConnection $connection, PretixClient $client, string $slug, array $raw): void
    {
        $code = $raw['code'] ?? null;

        if (! $code) {
            return;
        }

        PretixOrder::updateOrCreate(
            [
                'pretix_connection_id' => $connection->id,
                'event_slug' => $slug,
                'order_code' => $code,
            ],
            [
                'status' => $raw['status'] ?? null,
                'payment_provider' => $this->extractProvider($raw),
                'email' => $raw['email'] ?? null,
                'total' => isset($raw['total']) ? (float) $raw['total'] : null,
                'currency' => $raw['currency'] ?? null,
                'order_datetime' => $raw['datetime'] ?? null,
                'url' => $client->orderControlUrl($slug, $code),
                'raw_payload' => $raw,
            ],
        );
    }

    private function extractProvider(array $raw): ?string
    {
        if (filled($raw['payment_provider'] ?? null)) {
            return $raw['payment_provider'];
        }

        // Newer pretix API nests payments; take the most recent one's provider.
        $payments = $raw['payments'] ?? [];

        return filled($payments) ? Arr::get(Arr::last($payments), 'provider') : null;
    }
}
