<?php

namespace App\Filament\Resources\PretixPaymentConfirmationResource\Pages;

use App\Filament\Resources\PretixPaymentConfirmationResource;
use Filament\Resources\Pages\ListRecords;

class ListPretixPaymentConfirmations extends ListRecords
{
    protected static string $resource = PretixPaymentConfirmationResource::class;

    /**
     * Says what this list is for and where the switch behind it sits.
     *
     * Someone lands here because an order is paid that nobody paid by hand. The
     * first thing they need is not the data but the sentence explaining that a rule
     * did it, and where that rule is turned off.
     */
    public function getSubheading(): ?string
    {
        return 'Jede Meldung an pretix – und jede Verweigerung – mit Begründung. Aufgezeichnet wird '
            . 'sowohl, was TxWatch selbstständig auf bezahlt gesetzt hat, als auch was es bewusst '
            . 'liegen liess. Nichts hier lässt sich ändern oder löschen. '
            . 'Geschaltet wird die Automatik pro Event unter Events → „Zahlungen automatisch melden"; '
            . 'ohne diesen Schalter meldet TxWatch nichts von allein. Gemeldet wird nur bei offener '
            . 'Bestellung, auf den Cent passendem Betrag und offener Überweisungs-Zahlung in pretix, '
            . 'und höchstens einmal je Bestellung.';
    }
}
