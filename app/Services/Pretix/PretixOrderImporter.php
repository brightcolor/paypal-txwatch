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
    public function __construct(
        private readonly PretixReconciler $reconciler,
        private readonly PretixTransactionBooker $booker,
    ) {
    }

    /**
     * @param  callable(string, array<string, int|string>): void|null  $onProgress
     *         invoked with a human message + optional counter patch, for live logging
     * @return array{events: int, orders: int, booked: int, matched: int, mismatch: int, unmatched: int}
     */
    public function import(PretixConnection $connection, ?callable $onProgress = null): array
    {
        $client = new PretixClient($connection);
        $orderCount = 0;
        $progress = $onProgress ?? fn (string $m, array $p = []) => null;

        try {
            $progress('Lade Events aus pretix …');
            $events = $client->events();
            $total = count($events);
            $progress("{$total} Event(s) gefunden.", ['events_total' => $total]);

            foreach ($events as $i => $event) {
                $slug = $event['slug'];
                $index = $i + 1;
                $progress("Event {$index}/{$total}: {$slug} – lade Bestellungen …", ['events_done' => $i]);

                $eventOrders = 0;
                $client->ordersForEvent($slug, function (array $page) use ($connection, $client, $slug, &$orderCount, &$eventOrders, $progress) {
                    foreach ($page as $raw) {
                        $this->upsertOrder($connection, $client, $slug, $raw);
                        $orderCount++;
                        $eventOrders++;
                    }
                    $progress("… {$slug}: {$eventOrders} Bestellungen geladen", ['orders_imported' => $orderCount]);
                });

                $progress("Event {$index}/{$total}: {$slug} – {$eventOrders} Bestellungen.", ['events_done' => $index, 'orders_imported' => $orderCount]);
            }

            $progress('Verbuche Nicht-PayPal-Zahlungen (Überweisung etc.) …');
            $booking = $this->booker->book($connection, $progress);

            $progress('Gleiche mit PayPal-Transaktionen ab …');
            $reconciliation = $this->reconciler->reconcile($connection);
            $progress("Abgleich fertig: {$reconciliation['matched']} abgeglichen, {$reconciliation['mismatch']} Abweichung, {$reconciliation['unmatched']} nicht in pretix.", [
                'matched' => $reconciliation['matched'],
                'mismatch' => $reconciliation['mismatch'],
                'unmatched' => $reconciliation['unmatched'],
            ]);

            $connection->forceFill([
                'last_synced_at' => now(),
                'last_successful_sync_at' => now(),
                'last_error' => null,
            ])->save();

            return array_merge(
                ['events' => count($events), 'orders' => $orderCount, 'booked' => $booking['booked'] + $booking['updated']],
                $reconciliation,
            );
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
