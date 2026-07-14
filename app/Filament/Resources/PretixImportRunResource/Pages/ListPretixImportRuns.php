<?php

namespace App\Filament\Resources\PretixImportRunResource\Pages;

use App\Filament\Resources\PretixImportRunResource;
use Filament\Resources\Pages\ListRecords;

class ListPretixImportRuns extends ListRecords
{
    use \App\Filament\Concerns\ClampsRecordsPerPageOnReload;

    protected static string $resource = PretixImportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
