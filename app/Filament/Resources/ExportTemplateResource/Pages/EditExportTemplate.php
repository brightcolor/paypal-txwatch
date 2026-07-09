<?php

namespace App\Filament\Resources\ExportTemplateResource\Pages;

use App\Filament\Resources\ExportTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditExportTemplate extends EditRecord
{
    protected static string $resource = ExportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
