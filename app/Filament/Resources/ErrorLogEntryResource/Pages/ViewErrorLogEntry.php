<?php

namespace App\Filament\Resources\ErrorLogEntryResource\Pages;

use App\Filament\Resources\ErrorLogEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewErrorLogEntry extends ViewRecord
{
    protected static string $resource = ErrorLogEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggleResolved')
                ->label(fn () => $this->record->resolved ? 'Wieder öffnen' : 'Als erledigt markieren')
                ->icon(fn () => $this->record->resolved ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-check')
                ->action(function () {
                    $this->record->update(['resolved' => ! $this->record->resolved]);
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
