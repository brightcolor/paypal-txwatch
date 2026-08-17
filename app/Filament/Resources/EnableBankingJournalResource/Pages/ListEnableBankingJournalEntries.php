<?php

namespace App\Filament\Resources\EnableBankingJournalResource\Pages;

use App\Filament\Resources\EnableBankingJournalResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The journal list. No header actions: there is nothing to create here, and the
 * pull is triggered on "Bank verbinden", where its result is also explained.
 */
class ListEnableBankingJournalEntries extends ListRecords
{
    protected static string $resource = EnableBankingJournalResource::class;

    /**
     * Says up front what this list is - and, more importantly, what it is NOT.
     *
     * Without this line a table full of bank transactions inside an accounting
     * application looks like bookings. It is not: no report, no EÜR and no
     * reconciliation reads a single row of it.
     */
    public function getSubheading(): ?string
    {
        return 'Aufzeichnung des Bankabrufs über Enable Banking – vier Abrufe am Tag, alle sechs Stunden. '
            . 'Diese Einträge werden bewusst NICHT gebucht: sie wirken in keinem Bericht, in keiner EÜR und '
            . 'in keiner Zuordnung. '
            // Says what is NOT here, because a list is also read by what it lacks:
            // someone looking for a card fee should learn that it was dropped on
            // purpose, not wonder whether the pull missed it.
            . 'Aufgezeichnet werden nur Geldeingänge sowie Abbuchungen, die eine offene '
            . 'pretix-Bestellnummer im Verwendungszweck tragen – das sind Erstattungen. Alle übrigen '
            . 'Abbuchungen (Kartengebühren, Tankstellen, Daueraufträge) werden übergangen und gar nicht '
            . 'erst aufgezeichnet; wie viele es waren, steht in der Meldung nach jedem Abruf. '
            . 'Die Spalte „pretix-Auftrag" zeigt, wo eine offene Bestellnummer erkannt wurde – daran '
            . 'lässt sich vorab beurteilen, ob die spätere automatische Zahlungsmeldung greifen wird.';
    }
}
