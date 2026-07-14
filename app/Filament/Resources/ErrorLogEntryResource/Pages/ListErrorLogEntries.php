<?php

namespace App\Filament\Resources\ErrorLogEntryResource\Pages;

use App\Filament\Resources\ErrorLogEntryResource;
use App\Models\ErrorLogEntry;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListErrorLogEntries extends ListRecords
{
    use \App\Filament\Concerns\ClampsRecordsPerPageOnReload;

    protected static string $resource = ErrorLogEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pruneResolved')
                ->label('Erledigte löschen')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn () => ErrorLogEntry::where('resolved', true)->exists())
                ->action(fn () => ErrorLogEntry::where('resolved', true)->delete()),
        ];
    }
}
