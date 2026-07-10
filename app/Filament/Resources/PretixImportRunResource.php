<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PretixImportRunResource\Pages;
use App\Models\PretixImportRun;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PretixImportRunResource extends Resource
{
    protected static ?string $model = PretixImportRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'pretix';

    protected static ?string $navigationLabel = 'pretix-Importe';

    protected static ?string $modelLabel = 'pretix-Import';

    protected static ?string $pluralModelLabel = 'pretix-Importe';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage-pretix-connections') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            // Poll so a running import's progress updates live in the list.
            ->poll('3s')
            ->columns([
                Tables\Columns\TextColumn::make('connection.name')->label('Verbindung')->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        PretixImportRun::STATUS_RUNNING => 'läuft…',
                        PretixImportRun::STATUS_SUCCESS => 'fertig',
                        PretixImportRun::STATUS_FAILED => 'fehlgeschlagen',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        PretixImportRun::STATUS_RUNNING => 'warning',
                        PretixImportRun::STATUS_SUCCESS => 'success',
                        PretixImportRun::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('progress')
                    ->label('Fortschritt')
                    ->state(fn (PretixImportRun $r) => 'Events ' . $r->events_done . '/' . ($r->events_total ?? '?') . ' · ' . $r->orders_imported . ' Bestellungen'),
                Tables\Columns\TextColumn::make('current')
                    ->label('Aktuell')
                    ->state(fn (PretixImportRun $r) => filled($r->log) ? end($r->log)['m'] : '–')
                    ->wrap()
                    ->limit(70),
                Tables\Columns\TextColumn::make('matched')->label('abgeglichen')->color('success'),
                Tables\Columns\TextColumn::make('mismatch')->label('Abweichung')->color('danger'),
                Tables\Columns\TextColumn::make('unmatched')->label('nicht in pretix')->color('warning'),
                Tables\Columns\TextColumn::make('started_at')->label('Gestartet')->dateTime('d.m.Y H:i:s')->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPretixImportRuns::route('/'),
            'view' => Pages\ViewPretixImportRun::route('/{record}'),
        ];
    }
}
