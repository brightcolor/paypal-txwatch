<?php

namespace App\Filament\Resources\SettlementResource\Pages;

use App\Filament\Resources\SettlementResource;
use Filament\Resources\Pages\ListRecords;

class ListSettlements extends ListRecords
{
    use \App\Filament\Concerns\ClampsRecordsPerPageOnReload;

    protected static string $resource = SettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
