<?php

namespace App\Services\Pretix;

use App\Models\Event;
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
        private readonly OrderLog $orderLog,
    ) {
    }

    /**
     * @param  callable(string, array<string, int|string>): void|null  $onProgress
     *         invoked with a human message + optional counter patch, for live logging
     * @return array{events: int, orders: int, booked: int, matched: int, mismatch: int, unmatched: int}
     */
    public function import(PretixConnection $connection, ?callable $onProgress = null, ?int $runId = null): array
    {
        // Everything the three services write from here on is tied to this run.
        $this->orderLog->forRun($runId);

        $client = new PretixClient($connection);
        $orderCount = 0;
        $progress = $onProgress ?? fn (string $m, array $p = []) => null;

        // Incremental: only fetch orders pretix modified since the last
        // successful run (1h safety overlap). Unchanged orders are already
        // correctly booked, so the booker skips them too. First run: full.
        $since = $connection->last_successful_sync_at?->clone()->subHour();

        try {
            $progress($since ? "Lade Events aus pretix (Änderungen seit {$since->format('d.m.Y H:i')}) …" : 'Lade Events aus pretix (Vollimport) …');
            $events = $client->events();
            $total = count($events);
            $progress("{$total} Event(s) gefunden.", ['events_total' => $total]);

            foreach ($events as $event) {
                $this->upsertEvent($client, $event);
                $this->upsertItems($connection, $client, $event['slug']);
            }
            $progress('Lokale Events und Ticketarten angelegt/aktualisiert (aus pretix).');

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
                }, $since);

                $progress("Event {$index}/{$total}: {$slug} – {$eventOrders} Bestellungen.", ['events_done' => $index, 'orders_imported' => $orderCount]);
            }

            $progress('Verbuche Nicht-PayPal-Zahlungen (Überweisung etc.) …');
            $booking = $this->booker->book($connection, $progress, $since);

            $progress('Weise Transaktionen den Events zu (per pretix-Slug) …');
            $assigned = $this->assignEvents();
            $progress("{$assigned} Transaktionen automatisch einem Event zugewiesen.");

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

    /**
     * The event's ticket types, by name.
     *
     * Order positions refer to a ticket type by NUMBER - 3, 4, 23 on the real data -
     * and nobody selects a ticket type by typing 23. Stored rather than fetched when
     * needed, so choosing one does not depend on pretix answering at that moment.
     *
     * A failing call is not fatal: the export then offers no names for that event,
     * which is a smaller problem than an import that stops over reference data.
     */
    private function upsertItems(PretixConnection $connection, PretixClient $client, string $slug): void
    {
        foreach ($client->items($slug) as $id => $name) {
            \App\Models\PretixItem::updateOrCreate(
                [
                    'pretix_connection_id' => $connection->id,
                    'event_slug' => $slug,
                    'item_id' => (int) $id,
                ],
                ['name' => (string) $name],
            );
        }
    }

    private function upsertOrder(PretixConnection $connection, PretixClient $client, string $slug, array $raw): void
    {
        $code = $raw['code'] ?? null;

        if (! $code) {
            return;
        }

        /*
         * READ BEFORE WRITING. "Changed" is only a record if the previous values are
         * in it; after updateOrCreate they are gone, and a history line saying
         * "something changed" answers nothing.
         */
        $existing = PretixOrder::query()
            ->where('pretix_connection_id', $connection->id)
            ->where('event_slug', $slug)
            ->where('order_code', $code)
            ->first();

        $before = OrderLog::snapshot($existing);

        $order = PretixOrder::updateOrCreate(
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
                'tax_total' => $this->extractTaxTotal($raw),
                'currency' => $raw['currency'] ?? null,
                'order_datetime' => $raw['datetime'] ?? null,
                'url' => $client->orderControlUrl($slug, $code),
                'raw_payload' => $raw,
            ],
        );

        /*
         * The derived positions travel with the order. Written here rather than in a
         * listener, because the order and its positions have to be right at the same
         * moment: every audience figure reads the positions, and an order that is
         * already updated while its positions still describe the previous state is a
         * wrong number that nothing complains about.
         */
        app(\App\Services\Pretix\PositionWriter::class)->write($order);

        $after = OrderLog::snapshot($order->refresh());
        $changes = OrderLog::differences($before, $after);

        if (! $existing) {
            $this->orderLog->write(
                \App\Models\PretixOrderLogEntry::ACTION_NEW,
                $connection->id, $slug, $code,
                sprintf(
                    'Neu aus pretix übernommen: %s über %s, Zahlungsart %s.',
                    \App\Models\PretixOrderLogEntry::statusLabel($order->status),
                    $order->total !== null ? number_format((float) $order->total, 2, ',', '.') . ' ' . ($order->currency ?? '') : '?',
                    $order->payment_provider ?: 'unbekannt',
                ),
                $order, null, $order->status,
            );

            return;
        }

        if ($changes === []) {
            /*
             * RECORDED ANYWAY, and that is deliberate: the question "was my order
             * even seen" is asked far more often than "what changed", and an import
             * that returns an order without touching it is the answer to it. The
             * import is incremental, so pretix hands over only what it changed - this
             * stays a handful of rows per run rather than a thousand.
             */
            $this->orderLog->write(
                \App\Models\PretixOrderLogEntry::ACTION_UNCHANGED,
                $connection->id, $slug, $code,
                'Von pretix geliefert, keine Änderung gegenüber dem Bestand.',
                $order, $before['status'] ?? null, $order->status,
            );

            return;
        }

        $this->orderLog->write(
            \App\Models\PretixOrderLogEntry::ACTION_CHANGED,
            $connection->id, $slug, $code,
            'Aus pretix aktualisiert – ' . implode(', ', $changes) . '.',
            $order, $before['status'] ?? null, $order->status,
            ['changes' => $changes],
        );
    }

    /**
     * Creates/updates the local Event for a pretix event, enriched with the
     * details the customer export cover page needs: date, location and the
     * event image from pretix. name/date/venue are kept in sync with pretix on
     * every import; display_name stays as the user's PDF override, and a
     * manually set logo/venue is not overwritten with an empty pretix value.
     *
     * @param  array{slug: string, name: string, date_from?: ?string, date_to?: ?string, location?: ?string}  $event
     */
    private function upsertEvent(PretixClient $client, array $event): void
    {
        $slug = $event['slug'];

        if (blank($slug)) {
            return;
        }

        $model = Event::firstOrNew(['pretix_event_slug' => $slug]);
        $model->name = $event['name'] ?: $slug;

        if (filled($event['date_from'] ?? null)) {
            $model->event_date = \Illuminate\Support\Carbon::parse($event['date_from']);
        }
        if (filled($event['location'] ?? null)) {
            $model->venue = $event['location'];
        }

        // Fetch the event image once (only when we don't already have one).
        if (blank($model->logo_path)) {
            if ($url = $client->eventLogoUrl($slug)) {
                if ($bytes = $client->download($url)) {
                    $ext = pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'png';
                    $path = "event-logos/{$slug}." . strtolower($ext);
                    \Illuminate\Support\Facades\Storage::disk('public')->put($path, $bytes);
                    $model->logo_path = $path;
                }
            }
        }

        $model->save();
    }

    /**
     * Assigns transactions to their Event via the pretix slug found in the
     * order number ("Order <SLUG>-<CODE>"). Only fills unassigned transactions
     * - a manual assignment is never overwritten.
     */
    private function assignEvents(): int
    {
        $assigned = 0;

        // Deactivated events are retired: no new assignments happen for them
        // (existing assignments stay untouched). Longest slug first plus a
        // no-further-dash guard so 'sommerfest' can never swallow
        // 'sommerfest-2' orders: pretix order codes are dash-free, so after
        // "{slug}-" exactly ONE dash-free segment must follow (audit 2026-07-12).
        $events = Event::query()->whereNotNull('pretix_event_slug')->where('is_active', true)
            ->get()
            ->sortByDesc(fn (Event $e) => mb_strlen($e->pretix_event_slug));

        foreach ($events as $event) {
            // Escape LIKE wildcards in the slug; matching is case-insensitive
            // (pretix slugs are lowercase, PayPal custom fields are uppercase).
            $slug = addcslashes(strtolower($event->pretix_event_slug), '%_');

            $assigned += Transaction::query()
                ->whereNull('event_id')
                ->where(function ($q) use ($slug) {
                    $q->where(function ($q2) use ($slug) {
                        $q2->whereRaw('LOWER(custom_field) LIKE ?', ["order {$slug}-%"])
                            ->whereRaw('LOWER(custom_field) NOT LIKE ?', ["order {$slug}-%-%"]);
                    })->orWhere(function ($q2) use ($slug) {
                        $q2->whereRaw('LOWER(custom_field) LIKE ?', ["{$slug}-%"])
                            ->whereRaw('LOWER(custom_field) NOT LIKE ?', ["{$slug}-%-%"]);
                    });
                })
                ->update([
                    'event_id' => $event->id,
                    'assignment_method' => 'pretix',
                    'assignment_rule_id' => null,
                    'assigned_at' => now(),
                ]);
        }

        return $assigned;
    }

    /**
     * The order's actual VAT: sum of the positions' and fees' tax_value.
     * Null when the payload carries no positions (then exports fall back to
     * the configured flat rate).
     */
    private function extractTaxTotal(array $raw): ?float
    {
        if (! isset($raw['positions']) && ! isset($raw['fees'])) {
            return null;
        }

        $tax = 0.0;

        foreach (($raw['positions'] ?? []) as $position) {
            $tax += (float) ($position['tax_value'] ?? 0);
        }

        foreach (($raw['fees'] ?? []) as $orderFee) {
            $tax += (float) ($orderFee['tax_value'] ?? 0);
        }

        return round($tax, 2);
    }

    private function extractProvider(array $raw): ?string
    {
        if (filled($raw['payment_provider'] ?? null)) {
            return $raw['payment_provider'];
        }

        // Newer pretix API nests payments. Only CONFIRMED payments say how the
        // order was actually paid - a trailing failed/canceled attempt must not
        // relabel the order (audit 2026-07-12). Missing state = confirmed, for
        // older payloads.
        $confirmed = array_values(array_filter(
            $raw['payments'] ?? [],
            fn ($p) => (($p['state'] ?? 'confirmed') === 'confirmed'),
        ));

        return filled($confirmed) ? Arr::get(Arr::last($confirmed), 'provider') : null;
    }
}
