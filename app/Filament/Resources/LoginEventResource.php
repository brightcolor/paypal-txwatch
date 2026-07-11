<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoginEventResource\Pages;
use App\Models\LoginEvent;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only login history (successful + failed attempts). Admin only, System
 * group. Diagnostic data - prunable, not accounting/audit.
 */
class LoginEventResource extends Resource
{
    protected static ?string $model = LoginEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Login-Historie';

    protected static ?string $modelLabel = 'Login';

    protected static ?string $pluralModelLabel = 'Login-Historie';

    protected static ?int $navigationSort = 95;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i:s')->sortable(),
                Tables\Columns\IconColumn::make('successful')->label('Erfolg')->boolean(),
                Tables\Columns\TextColumn::make('user.name')->label('Benutzer')
                    ->placeholder('–')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('E-Mail')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('ip')->label('IP')->searchable(),
                Tables\Columns\TextColumn::make('user_agent')->label('Gerät/Browser')->limit(40)
                    ->tooltip(fn (LoginEvent $r) => $r->user_agent)->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('successful')->label('Ergebnis')
                    ->trueLabel('nur erfolgreiche')->falseLabel('nur fehlgeschlagene')->placeholder('Alle'),
            ])
            ->emptyStateHeading('Noch keine Login-Ereignisse')
            ->emptyStateIcon('heroicon-o-finger-print');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoginEvents::route('/'),
        ];
    }
}
