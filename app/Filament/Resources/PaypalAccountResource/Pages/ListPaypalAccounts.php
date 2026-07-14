<?php

namespace App\Filament\Resources\PaypalAccountResource\Pages;

use App\Filament\Resources\PaypalAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPaypalAccounts extends ListRecords
{
    use \App\Filament\Concerns\ClampsRecordsPerPageOnReload;

    protected static string $resource = PaypalAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
