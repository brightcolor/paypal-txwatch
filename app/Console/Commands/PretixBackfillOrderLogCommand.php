<?php

namespace App\Console\Commands;

use App\Models\PretixOrder;
use App\Models\PretixOrderLogEntry;
use App\Models\Transaction;
use App\Services\Pretix\OrderLog;
use Illuminate\Console\Command;

/**
 * Writes one opening line for every order that has no history yet.
 *
 * WHY THIS IS NEEDED AT ALL. The per-order history only fills up when an import
 * touches an order, and the import is incremental - pretix hands over what it
 * changed since the last run. So the thousand-odd orders that were already here
 * when the history was introduced would never get a line, and the one order someone
 * actually looks up shows "no history" - which reads like the recording is broken.
 *
 * WHAT IT WRITES IS WHAT WE KNOW, not what we wish we knew: the state now, and
 * whether a transaction was ever booked or reconciled for it. It does NOT
 * reconstruct a past - there is no record of what happened before the recording
 * existed, and inventing one would make the log a worse witness than an empty one.
 *
 * Idempotent: an order that already has a line is left alone, so this can be run
 * again after an import without producing a second opening entry.
 */
class PretixBackfillOrderLogCommand extends Command
{
    protected $signature = 'pretix:backfill-order-log {--dry-run : Nur zählen, nichts schreiben}';

    protected $description = 'Schreibt für Bestellungen ohne Verlauf eine einmalige Bestandsaufnahme.';

    public function handle(OrderLog $log): int
    {
        $trocken = (bool) $this->option('dry-run');

        // The codes that already have a line. One query rather than one per order:
        // with 1130 orders the per-order variant is 1130 round trips for nothing.
        $bekannt = PretixOrderLogEntry::query()
            ->select(['order_code', 'event_slug'])
            ->distinct()
            ->get()
            ->map(fn ($r) => $r->event_slug . '|' . $r->order_code)
            ->flip();

        $geschrieben = 0;
        $uebersprungen = 0;

        PretixOrder::query()->orderBy('id')->chunk(200, function ($orders) use (
            $log, $bekannt, $trocken, &$geschrieben, &$uebersprungen
        ) {
            foreach ($orders as $order) {
                if ($bekannt->has($order->event_slug . '|' . $order->order_code)) {
                    $uebersprungen++;

                    continue;
                }

                if ($trocken) {
                    $geschrieben++;

                    continue;
                }

                $log->write(
                    PretixOrderLogEntry::ACTION_BASELINE,
                    $order->pretix_connection_id,
                    $order->event_slug,
                    $order->order_code,
                    $this->satz($order),
                    $order,
                    null,
                    $order->status,
                );

                $geschrieben++;
            }
        });

        $this->info(sprintf(
            '%s%d Bestandsaufnahmen, %d Bestellungen hatten schon einen Verlauf.',
            $trocken ? 'PROBELAUF: ' : '',
            $geschrieben,
            $uebersprungen,
        ));

        return self::SUCCESS;
    }

    /** What is known about this order right now - and what is not. */
    private function satz(PretixOrder $order): string
    {
        $gebucht = Transaction::query()->where('pretix_order_id', $order->id)->count();

        return sprintf(
            'Bestand bei Einführung der Aufzeichnung: %s, %s, Zahlungsart %s. %s '
            . 'Was vorher mit dieser Bestellung geschah, ist nicht aufgezeichnet worden.',
            PretixOrderLogEntry::statusLabel($order->status),
            $order->total !== null
                ? number_format((float) $order->total, 2, ',', '.') . ' ' . ($order->currency ?? 'EUR')
                : 'Betrag unbekannt',
            $order->payment_provider ?: 'unbekannt',
            $gebucht > 0
                ? sprintf('%d Transaktion(en) sind ihr zugeordnet.', $gebucht)
                : 'Es ist ihr keine Transaktion zugeordnet.',
        );
    }
}
