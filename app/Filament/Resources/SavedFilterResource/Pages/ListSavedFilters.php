<?php

namespace App\Filament\Resources\SavedFilterResource\Pages;

use App\Filament\Resources\SavedFilterResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSavedFilters extends ListRecords
{
    protected static string $resource = SavedFilterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
