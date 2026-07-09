<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Einstellungen';

    protected static ?string $modelLabel = 'Benutzer';

    protected static ?string $pluralModelLabel = 'Benutzer';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage-users') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(255),
            Forms\Components\TextInput::make('email')->label('E-Mail')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
            Forms\Components\TextInput::make('password')
                ->label('Passwort')
                ->password()
                ->revealable()
                ->dehydrateStateUsing(fn ($state) => \Illuminate\Support\Facades\Hash::make($state))
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn (string $context) => $context === 'create'),
            Forms\Components\Select::make('roles')
                ->label('Rolle')
                ->relationship('roles', 'name')
                ->multiple()
                ->preload()
                ->required(),
            Forms\Components\Select::make('customer_id')
                ->label('Kunde (für Rolle "customer")')
                ->relationship('customer', 'name')
                ->searchable()
                ->helperText('Beschränkt den Zugriff eines Kunden-Nutzers auf dessen eigene Events/Reports.'),
            Forms\Components\Toggle::make('is_active')->label('Aktiv')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('E-Mail')->searchable(),
                Tables\Columns\TextColumn::make('roles.name')->label('Rolle')->badge(),
                Tables\Columns\TextColumn::make('customer.name')->label('Kunde'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
