<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Filament\Resources\TransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \App\Filament\Actions\ExportPreviewAction::make(),
            \App\Filament\Actions\ExportFilterAction::make(),
            \App\Filament\Actions\SaveFilterAction::make(),
        ];
    }
}
