<?php

namespace App\Filament\Resources\PaypalAccountResource\Pages;

use App\Filament\Resources\PaypalAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPaypalAccount extends ViewRecord
{
    protected static string $resource = PaypalAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
