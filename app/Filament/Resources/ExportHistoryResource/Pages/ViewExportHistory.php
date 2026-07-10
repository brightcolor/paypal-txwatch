<?php

namespace App\Filament\Resources\ExportHistoryResource\Pages;

use App\Filament\Resources\ExportHistoryResource;
use Filament\Resources\Pages\ViewRecord;

class ViewExportHistory extends ViewRecord
{
    protected static string $resource = ExportHistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
