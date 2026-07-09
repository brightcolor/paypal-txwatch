<?php

namespace App\Filament\Resources\SyncRunResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ImportErrorsRelationManager extends RelationManager
{
    protected static string $relationship = 'importErrors';

    protected static ?string $title = 'Fehler';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('message')
            ->columns([
                Tables\Columns\TextColumn::make('error_type')->label('Typ')->badge(),
                Tables\Columns\TextColumn::make('transaction_id')->label('Transaktions-ID'),
                Tables\Columns\TextColumn::make('window_start')->label('Zeitraum von')->dateTime('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('window_end')->label('bis')->dateTime('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('message')->label('Meldung')->wrap(),
                Tables\Columns\TextColumn::make('created_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
