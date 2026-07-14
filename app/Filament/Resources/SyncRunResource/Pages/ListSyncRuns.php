<?php

namespace App\Filament\Resources\SyncRunResource\Pages;

use App\Filament\Resources\SyncRunResource;
use Filament\Resources\Pages\ListRecords;

class ListSyncRuns extends ListRecords
{
    use \App\Filament\Concerns\ClampsRecordsPerPageOnReload;

    protected static string $resource = SyncRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
