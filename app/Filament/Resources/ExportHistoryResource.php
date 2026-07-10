<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExportHistoryResource\Pages;
use App\Models\ExportHistory;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class ExportHistoryResource extends Resource
{
    protected static ?string $model = ExportHistory::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Exporte';

    protected static ?string $navigationLabel = 'Exporthistorie';

    protected static ?string $modelLabel = 'Export';

    protected static ?string $pluralModelLabel = 'Exporthistorie';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage-exports') ?? false;
    }

    /**
     * Non-admins only ever see their own exports - these can contain
     * unmasked personal data, so they are not shared across users.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->hasRole('admin')) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Erstellt')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Erstellt von'),
                Tables\Columns\BadgeColumn::make('format')->label('Format'),
                Tables\Columns\TextColumn::make('exportTemplate.name')->label('Vorlage')->default('Ad-hoc'),
                Tables\Columns\TextColumn::make('row_count')->label('Zeilen'),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Läuft ab')
                    ->dateTime('d.m.Y H:i')
                    ->color(fn (ExportHistory $record) => $record->isExpired() ? 'danger' : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('format')->options([
                    'pdf' => 'PDF', 'csv' => 'CSV', 'xlsx' => 'XLSX',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (ExportHistory $record) => ! $record->isExpired() && Storage::disk('local')->exists($record->file_path))
                    ->action(function (ExportHistory $record) {
                        if ($record->isExpired() || ! Storage::disk('local')->exists($record->file_path)) {
                            Notification::make()->title('Export ist abgelaufen oder nicht mehr vorhanden.')->danger()->send();

                            return null;
                        }

                        $content = Storage::disk('local')->get($record->file_path);
                        $filename = basename($record->file_path);

                        return response()->streamDownload(fn () => print ($content), $filename);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExportHistories::route('/'),
            'view' => Pages\ViewExportHistory::route('/{record}'),
        ];
    }
}
