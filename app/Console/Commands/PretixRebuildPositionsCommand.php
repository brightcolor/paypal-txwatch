<?php

namespace App\Console\Commands;

use App\Models\PretixOrder;
use App\Services\Pretix\PositionWriter;
use Illuminate\Console\Command;

/**
 * Rebuilds `pretix_positions` from the stored orders.
 *
 * NEEDED because the table is derived: it starts empty, it has to be filled from a
 * stock that was imported long before it existed, and after any change to the
 * writer it has to be possible to throw the rows away and get them again.
 *
 * Idempotent: every order is rewritten in full, so running it twice leaves the
 * same rows behind.
 */
class PretixRebuildPositionsCommand extends Command
{
    protected $signature = 'pretix:rebuild-positions {--event= : Nur diese Veranstaltung (pretix-Slug)}';

    protected $description = 'Baut die Ticketpositionen aus den gespeicherten pretix-Bestellungen neu auf.';

    public function handle(PositionWriter $writer): int
    {
        $slug = $this->option('event');

        $gelesen = 0;
        $geschrieben = 0;
        $ohnePositionen = 0;

        PretixOrder::query()
            ->when($slug, fn ($query) => $query->where('event_slug', $slug))
            ->orderBy('id')
            ->chunkById(200, function ($seite) use ($writer, &$gelesen, &$geschrieben, &$ohnePositionen) {
                foreach ($seite as $order) {
                    $gelesen++;
                    $zeilen = $writer->write($order);
                    $geschrieben += $zeilen;

                    if ($zeilen === 0) {
                        $ohnePositionen++;
                    }
                }
            });

        $this->info(sprintf(
            '%d Bestellungen gelesen, %d Positionen geschrieben, %d Bestellungen ohne aktive Position.',
            $gelesen,
            $geschrieben,
            $ohnePositionen,
        ));

        if ($gelesen === 0) {
            $this->warn($slug
                ? "Zur Veranstaltung „{$slug}\" liegen keine Bestellungen vor. Prüfe den Slug in der Eventverwaltung."
                : 'Es liegen keine pretix-Bestellungen vor. Starte zuerst einen pretix-Import.');
        }

        return self::SUCCESS;
    }
}
