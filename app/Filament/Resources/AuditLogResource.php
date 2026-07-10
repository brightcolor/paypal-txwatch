<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLogEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only view onto the append-only audit trail (Spatie Activitylog, via
 * App\Models\AuditLogEntry). There is deliberately no create/edit/delete here:
 * entries are written by the app (e.g. Transaction::markIrrelevant()) and can
 * never be modified or removed - not from the UI, not from anywhere.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLogEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Audit-Log';

    protected static ?string $modelLabel = 'Audit-Log-Eintrag';

    protected static ?string $pluralModelLabel = 'Audit-Log';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view-audit-log') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Zeitpunkt')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Aktion')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Benutzer')
                    ->default('– System –')
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Objekt')
                    ->formatStateUsing(fn (?string $state, AuditLogEntry $record) => $state
                        ? class_basename($state) . ' #' . $record->subject_id
                        : '–')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Grund')
                    ->state(fn (AuditLogEntry $record) => $record->properties['reason'] ?? '–')
                    ->wrap()
                    ->tooltip(fn (AuditLogEntry $record) => $record->properties['reason'] ?? null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('log_name')
                    ->label('Log')
                    ->options(fn () => AuditLogEntry::query()
                        ->distinct()
                        ->pluck('log_name', 'log_name')
                        ->filter()
                        ->all()),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLog::route('/'),
        ];
    }
}
