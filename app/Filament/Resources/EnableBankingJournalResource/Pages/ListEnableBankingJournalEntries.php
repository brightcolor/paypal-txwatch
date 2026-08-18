<?php

namespace App\Filament\Resources\EnableBankingJournalResource\Pages;

use App\Filament\Resources\EnableBankingJournalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * The journal list.
 *
 * Nothing is created here - the pull is triggered on "Bank verbinden", where its
 * result is also explained. The one header action is a shortcut to exactly the
 * entries the number on the menu item counts.
 */
class ListEnableBankingJournalEntries extends ListRecords
{
    protected static string $resource = EnableBankingJournalResource::class;

    /**
     * THE NUMBER ON THE MENU ITEM, MADE CLICKABLE.
     *
     * A badge says how much is waiting but not which rows - and finding them meant
     * knowing which of six filters reproduces that number. Both read the same scope
     * (EnableBankingJournalEntry::scopeNeedsDecision), so the button cannot show a
     * different set than the number promises.
     *
     * Shown while there is something to decide, and also while the filter is on, so
     * the way back exists after the last entry has been dealt with.
     */
    protected function getHeaderActions(): array
    {
        $offen = (int) (EnableBankingJournalResource::getNavigationBadge() ?? 0);
        $aktiv = $this->istGefiltert();

        if ($offen === 0 && ! $aktiv) {
            return [];
        }

        return [
            Actions\Action::make('zu_entscheiden')
                ->label($aktiv ? 'Alle Einträge anzeigen' : sprintf('Nur die %d zu entscheiden', $offen))
                ->icon($aktiv ? 'heroicon-o-list-bullet' : 'heroicon-o-bell-alert')
                ->color($aktiv ? 'gray' : 'warning')
                ->tooltip($aktiv
                    ? 'Zurück zur vollständigen Aufzeichnung.'
                    : 'Zeigt genau die Einträge, die die Zahl am Menüpunkt ergeben: offene Bestellungen, '
                        . 'zweite Geldeingänge und Vorschläge.')
                ->action(fn () => $aktiv ? $this->alleZeigen() : $this->nurZuEntscheiden()),
        ];
    }

    /** Is the work-list filter currently on? */
    private function istGefiltert(): bool
    {
        return (bool) ($this->tableFilters[EnableBankingJournalResource::FILTER_ZU_TUN]['isActive'] ?? false);
    }

    private function nurZuEntscheiden(): void
    {
        $this->tableFilters[EnableBankingJournalResource::FILTER_ZU_TUN] = ['isActive' => true];

        // Filament's own hook, not just a property write: it persists the filter and
        // resets the paginator. Without it a filtered list can stay on a page that no
        // longer exists and come up empty.
        $this->updatedTableFilters();
    }

    private function alleZeigen(): void
    {
        $this->tableFilters[EnableBankingJournalResource::FILTER_ZU_TUN] = ['isActive' => false];
        $this->updatedTableFilters();
    }

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
            . 'Die Spalte „pretix-Auftrag" zeigt, welche Bestellnummer erkannt wurde, die Spalte '
            . '„Zustand", was daraus folgt: „offen – zu buchen" ist Arbeit, „bereits bezahlt" nur '
            . 'Information, „mögliche Doppelzahlung" ein Fall zum Prüfen. Der Knopf oben rechts zeigt '
            . 'genau die Einträge, die die Zahl am Menüpunkt ergeben.';
    }
}
