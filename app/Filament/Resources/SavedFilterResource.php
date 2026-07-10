<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SavedFilterResource\Pages;
use App\Models\SavedFilter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SavedFilterResource extends Resource
{
    protected static ?string $model = SavedFilter::class;

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $navigationGroup = 'Transaktionen';

    protected static ?string $modelLabel = 'Gespeicherter Filter';

    protected static ?string $pluralModelLabel = 'Gespeicherte Filter';

    /**
     * Saved filters are only ever created through the Transactions table's
     * "Filter speichern" action (App\Filament\Actions\SaveFilterAction), which
     * captures the live filter state and the current user_id. A standalone
     * create form can't capture either - it would insert a NULL `filters`
     * (violating the NOT NULL column) and a meaningless empty filter - so
     * creation is disabled here on purpose.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->hasRole('admin')) {
            $query->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('is_shared', true));
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(255),
            Forms\Components\Textarea::make('description')->label('Beschreibung')->rows(2),
            Forms\Components\Toggle::make('is_shared')
                ->label('Über Link teilbar')
                ->helperText('Erzeugt einen Share-Token für eine teilbare Filter-URL.'),
            Forms\Components\Placeholder::make('share_url')
                ->label('Teilbare URL')
                ->content(fn (?SavedFilter $record) => $record?->share_token
                    ? route('filters.shared', $record->share_token)
                    : '– noch nicht geteilt –')
                ->visible(fn (?SavedFilter $record) => $record?->is_shared),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('user.name')->label('Erstellt von'),
                Tables\Columns\IconColumn::make('is_shared')->label('Geteilt')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->label('Erstellt')->dateTime('d.m.Y H:i'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSavedFilters::route('/'),
            'edit' => Pages\EditSavedFilter::route('/{record}/edit'),
        ];
    }
}
