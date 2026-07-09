<?php

namespace App\Filament\Resources\PaypalAccountResource\Pages;

use App\Filament\Resources\PaypalAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPaypalAccount extends EditRecord
{
    protected static string $resource = PaypalAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
