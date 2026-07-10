<?php

namespace App\Filament\Resources\SavedFilterResource\Pages;

use App\Filament\Resources\SavedFilterResource;
use Filament\Resources\Pages\ListRecords;

class ListSavedFilters extends ListRecords
{
    protected static string $resource = SavedFilterResource::class;

    // No create action on purpose: saved filters are created only via the
    // Transactions table's "Filter speichern" action - see SavedFilterResource::canCreate().
    protected function getHeaderActions(): array
    {
        return [];
    }
}
