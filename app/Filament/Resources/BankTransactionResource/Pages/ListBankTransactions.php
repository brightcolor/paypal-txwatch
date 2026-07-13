<?php

namespace App\Filament\Resources\BankTransactionResource\Pages;

use App\Filament\Resources\BankTransactionResource;
use App\Services\Bank\BankStatementImporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListBankTransactions extends ListRecords
{
    protected static string $resource = BankTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import')
                ->label('Kontoauszug importieren')
                ->icon('heroicon-o-arrow-up-tray')
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label('Datei (CAMT.053 XML oder MT940)')
                        ->disk('local')
                        ->directory('bank-imports')
                        ->required()
                        ->helperText('Aus dem Sparkassen-Online-Banking: Umsätze als CAMT.053 (XML) oder MT940 exportieren und hier hochladen.'),
                ])
                ->action(function (array $data) {
                    $path = $data['file'];

                    try {
                        $content = Storage::disk('local')->get($path);
                        $result = app(BankStatementImporter::class)->import($content);

                        Notification::make()
                            ->title('Kontoauszug importiert')
                            ->body("{$result['imported']} neu, {$result['skipped']} bereits vorhanden, {$result['matched']} automatisch zugeordnet.")
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Import fehlgeschlagen')->body($e->getMessage())->danger()->persistent()->send();
                    } finally {
                        Storage::disk('local')->delete($path);
                    }
                }),
        ];
    }
}
