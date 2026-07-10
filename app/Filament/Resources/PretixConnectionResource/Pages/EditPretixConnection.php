<?php

namespace App\Filament\Resources\PretixConnectionResource\Pages;

use App\Filament\Resources\PretixConnectionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPretixConnection extends EditRecord
{
    protected static string $resource = PretixConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
