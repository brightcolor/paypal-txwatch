<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SyncRunResource\Pages;
use App\Filament\Resources\SyncRunResource\RelationManagers;
use App\Models\SyncRun;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'PayPal';

    protected static ?string $navigationLabel = 'Sync-Läufe';

    protected static ?string $modelLabel = 'Sync-Lauf';

    protected static ?string $pluralModelLabel = 'Sync-Läufe';

    protected static ?string $recordTitleAttribute = 'id';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view-sync-logs') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('paypalAccount.name')->label('Konto')->searchable(),
                Tables\Columns\BadgeColumn::make('type')
                    ->label('Typ')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        SyncRun::TYPE_SCHEDULED => 'Automatisch',
                        SyncRun::TYPE_MANUAL => 'Manuell',
                        SyncRun::TYPE_BACKFILL => 'Backfill',
                        SyncRun::TYPE_CSV_IMPORT => 'CSV-Import',
                        default => $state,
                    }),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => SyncRun::STATUS_SUCCESS,
                        'warning' => SyncRun::STATUS_PARTIAL,
                        'danger' => SyncRun::STATUS_FAILED,
                        'gray' => SyncRun::STATUS_RUNNING,
                    ]),
                Tables\Columns\TextColumn::make('window_start')->label('Zeitraum von')->dateTime('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('window_end')->label('bis')->dateTime('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('started_at')->label('Gestartet')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('duration_ms')->label('Dauer (ms)')->sortable(),
                Tables\Columns\TextColumn::make('imported_count')->label('Neu')->color('success'),
                Tables\Columns\TextColumn::make('updated_count')->label('Aktualisiert')->color('info'),
                Tables\Columns\TextColumn::make('skipped_count')->label('Übersprungen'),
                Tables\Columns\TextColumn::make('error_count')->label('Fehler')->color('danger'),
                Tables\Columns\TextColumn::make('api_requests_count')->label('API-Requests'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    SyncRun::STATUS_SUCCESS => 'Erfolgreich',
                    SyncRun::STATUS_PARTIAL => 'Teilweise',
                    SyncRun::STATUS_FAILED => 'Fehlgeschlagen',
                    SyncRun::STATUS_RUNNING => 'Läuft',
                ]),
                Tables\Filters\SelectFilter::make('paypal_account_id')
                    ->label('Konto')
                    ->relationship('paypalAccount', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ImportErrorsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncRuns::route('/'),
            'view' => Pages\ViewSyncRun::route('/{record}'),
        ];
    }
}
