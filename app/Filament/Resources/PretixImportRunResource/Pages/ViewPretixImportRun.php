<?php

namespace App\Filament\Resources\PretixImportRunResource\Pages;

use App\Filament\Resources\PretixImportRunResource;
use App\Models\PretixImportRun;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

class ViewPretixImportRun extends ViewRecord
{
    protected static string $resource = PretixImportRunResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Import')
                ->columns(4)
                ->schema([
                    TextEntry::make('connection.name')->label('Verbindung'),
                    TextEntry::make('status')->label('Status')->badge(),
                    TextEntry::make('started_at')->label('Gestartet')->dateTime('d.m.Y H:i:s'),
                    TextEntry::make('finished_at')->label('Beendet')->dateTime('d.m.Y H:i:s')->placeholder('–'),
                    TextEntry::make('events_done')->label('Events')
                        ->state(fn (PretixImportRun $r) => $r->events_done . '/' . ($r->events_total ?? '?')),
                    TextEntry::make('orders_imported')->label('Bestellungen'),
                    TextEntry::make('matched')->label('abgeglichen'),
                    TextEntry::make('mismatch')->label('Abweichung'),
                ]),

            Section::make('Verlauf')
                ->schema([
                    TextEntry::make('log')
                        ->label('')
                        ->state(fn (PretixImportRun $r) => new HtmlString(
                            '<pre style="white-space: pre-wrap; font-size: 12px; line-height: 1.4;">'
                            . e(collect($r->log ?? [])->map(fn ($l) => ($l['t'] ?? '') . '  ' . ($l['m'] ?? ''))->implode("\n"))
                            . '</pre>'
                        )),
                ]),

            Section::make('Fehler')
                ->visible(fn (PretixImportRun $r) => filled($r->error))
                ->schema([
                    TextEntry::make('error')->label('')->color('danger'),
                ]),
        ]);
    }
}
