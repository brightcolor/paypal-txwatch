<?php

namespace App\Filament\Actions;

use App\Services\Export\ExportDataBuilder;
use Filament\Actions\Action;

/**
 * Read-only preview of what the current filtered export would contain: the
 * first rows and the grand total, using the same ExportDataBuilder as the real
 * export - so the operator can sanity-check columns and figures before
 * generating/downloading a file.
 */
class ExportPreviewAction
{
    private const PREVIEW_ROWS = 25;

    public static function make(): Action
    {
        return Action::make('exportPreview')
            ->label('Vorschau')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading('Export-Vorschau')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Schließen')
            // Quick sanity-check with the default column set / VAT; template and
            // rate are chosen in the real "Exportieren" dialog.
            ->modalContent(function ($livewire) {
                // Limit the query so a preview never materializes the whole result.
                $query = (clone $livewire->getTableQueryForExport())->limit(self::PREVIEW_ROWS);

                $built = app(ExportDataBuilder::class)->build($query, null, ['vat_rate' => 19.0]);

                return view('filament.export-preview', [
                    'data' => $built,
                    'limit' => self::PREVIEW_ROWS,
                ]);
            });
    }
}
