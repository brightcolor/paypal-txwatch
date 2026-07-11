<?php

namespace App\Filament\Resources\LoginEventResource\Pages;

use App\Filament\Resources\LoginEventResource;
use App\Models\LoginEvent;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLoginEvents extends ListRecords
{
    protected static string $resource = LoginEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('prune')
                ->label('Älter als 90 Tage löschen')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn () => LoginEvent::where('created_at', '<', now()->subDays(90))->exists())
                ->action(fn () => LoginEvent::where('created_at', '<', now()->subDays(90))->delete()),
        ];
    }
}
