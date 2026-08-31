<?php

namespace App\Filament\Resources\PretixOrderResource\Pages;

use App\Filament\Resources\PretixOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListPretixOrders extends ListRecords
{
    protected static string $resource = PretixOrderResource::class;

    /**
     * Says what this list is and, more usefully, what it is not.
     *
     * Someone lands here because an order seemed to be missing. The first thing they
     * need is that every status is in here - an order not showing up is not the
     * import having skipped it.
     */
    public function getSubheading(): ?string
    {
        return 'Alle Bestellungen aus pretix, in JEDEM Status – offen, bezahlt, abgelaufen und '
            . 'storniert. Nichts wird hier ausgefiltert; fehlt eine Bestellung, hat der Import sie '
            . 'wirklich nicht bekommen. Die Daten gehören pretix und lassen sich hier nicht ändern; '
            . 'die Bestellnummer öffnet die Bestellung in pretix. Über „Verlauf" steht zu jeder '
            . 'Bestellung, wann der Import sie gefunden hat, was sich geändert hat und was daraufhin '
            . 'geschah – verbucht, abgeglichen oder bewusst übergangen.';
    }
}
