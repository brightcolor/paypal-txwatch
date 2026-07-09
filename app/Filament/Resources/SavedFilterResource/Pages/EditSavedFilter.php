<?php

namespace App\Filament\Resources\SavedFilterResource\Pages;

use App\Filament\Resources\SavedFilterResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSavedFilter extends EditRecord
{
    protected static string $resource = SavedFilterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
