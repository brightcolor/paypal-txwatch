<?php

namespace App\Services\Pretix;

use App\Models\PretixOrder;
use App\Models\PretixOrderLogEntry;

/**
 * Writes the per-order history of an import.
 *
 * ONE PLACE, called from the three services that touch orders during a run - the
 * importer, the booker and the reconciler. Each writing its own rows would be three
 * shapes of the same record, and the history would read differently depending on
 * which step produced the line.
 *
 * THE RUN ID IS HELD HERE rather than threaded through every call. The booker and
 * the reconciler are called from inside the import and have no reason to know about
 * import runs; making them take a run id would put bookkeeping into two services
 * that do neither.
 */
class OrderLog
{
    private ?int $runId = null;

    /** Ties everything written from now on to this import run. */
    public function forRun(?int $runId): void
    {
        $this->runId = $runId;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function write(
        string $action,
        ?int $connectionId,
        ?string $eventSlug,
        ?string $orderCode,
        string $message,
        ?PretixOrder $order = null,
        ?string $statusBefore = null,
        ?string $statusAfter = null,
        array $context = [],
    ): void {
        /*
         * THE SAME SENTENCE TWICE IS NOT A SECOND RECORD - and here that is not a
         * nicety. The reconciler walks EVERY order on EVERY import, not just the
         * changed ones: measured on the real data that was 1056 identical lines per
         * run, every 30 minutes, about 50.000 rows a day burying the handful that
         * say something. Only a CHANGED answer is written.
         */
        if ($this->alreadySaid($action, $eventSlug, $orderCode, $message)) {
            return;
        }

        PretixOrderLogEntry::create([
            'pretix_import_run_id' => $this->runId,
            'pretix_connection_id' => $connectionId,
            'event_slug' => $eventSlug,
            'order_code' => $orderCode,
            'pretix_order_id' => $order?->id,
            'action' => $action,
            'status_before' => $statusBefore,
            'status_after' => $statusAfter,
            'total' => $order?->total,
            'message' => $message,
            'context' => $context ?: null,
            'at' => now(),
        ]);
    }

    /**
     * Did the last entry of this kind for this order say exactly the same thing?
     *
     * Compared per KIND: a booking line and a reconciliation line are two different
     * facts about the same order and must not suppress each other.
     */
    private function alreadySaid(string $action, ?string $eventSlug, ?string $orderCode, string $message): bool
    {
        if ($orderCode === null) {
            return false;
        }

        $letzte = PretixOrderLogEntry::query()
            ->where('order_code', $orderCode)
            ->where('event_slug', $eventSlug)
            ->where('action', $action)
            ->orderByDesc('id')
            ->value('message');

        return $letzte === $message;
    }

    /**
     * What an order looked like before the import touched it.
     *
     * Only the fields worth a history line. `raw_payload` is deliberately out: it
     * changes on every pretix-side edit down to timestamps, and a "changed" line for
     * that is noise that hides the changes that matter.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(?PretixOrder $order): array
    {
        if (! $order) {
            return [];
        }

        return [
            'status' => $order->status,
            'total' => $order->total !== null ? (float) $order->total : null,
            'payment_provider' => $order->payment_provider,
            'email' => $order->email,
        ];
    }

    /**
     * The differences between two snapshots, in words.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<int, string>
     */
    public static function differences(array $before, array $after): array
    {
        $labels = [
            'status' => 'Status',
            'total' => 'Betrag',
            'payment_provider' => 'Zahlungsart',
            'email' => 'E-Mail',
        ];

        $changes = [];

        foreach ($labels as $key => $label) {
            $von = $before[$key] ?? null;
            $bis = $after[$key] ?? null;

            if ($von == $bis) {
                continue;
            }

            $changes[] = sprintf(
                '%s: %s → %s',
                $label,
                $key === 'status' ? PretixOrderLogEntry::statusLabel($von) : ($von ?? '–'),
                $key === 'status' ? PretixOrderLogEntry::statusLabel($bis) : ($bis ?? '–'),
            );
        }

        return $changes;
    }
}
