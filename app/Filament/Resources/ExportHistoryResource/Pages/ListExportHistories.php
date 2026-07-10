<?php

namespace App\Filament\Resources\ExportHistoryResource\Pages;

use App\Filament\Resources\ExportHistoryResource;
use Filament\Resources\Pages\ListRecords;

class ListExportHistories extends ListRecords
{
    protected static string $resource = ExportHistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
