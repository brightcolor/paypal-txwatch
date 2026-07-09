<?php

namespace App\Filament\Resources\EventResource\RelationManagers;

use App\Models\EventAssignmentRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AssignmentRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'assignmentRules';

    protected static ?string $title = 'Zuordnungsregeln';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('match_type')
                ->label('Regeltyp')
                ->options([
                    EventAssignmentRule::TYPE_CUSTOM_FIELD_CONTAINS => 'Custom Field enthält',
                    EventAssignmentRule::TYPE_CUSTOM_FIELD_REGEX => 'Custom Field Regex',
                    EventAssignmentRule::TYPE_INVOICE_ID_CONTAINS => 'Invoice ID enthält',
                    EventAssignmentRule::TYPE_INVOICE_ID_REGEX => 'Invoice ID Regex',
                    EventAssignmentRule::TYPE_AMOUNT_RANGE => 'Betragsbereich',
                    EventAssignmentRule::TYPE_DATE_RANGE => 'Zeitraum',
                    EventAssignmentRule::TYPE_PAYPAL_ACCOUNT => 'PayPal-Konto',
                ])
                ->reactive()
                ->required(),
            Forms\Components\TextInput::make('pattern')
                ->label('Muster/Zeichenkette')
                ->visible(fn (Forms\Get $get) => in_array($get('match_type'), [
                    EventAssignmentRule::TYPE_CUSTOM_FIELD_CONTAINS,
                    EventAssignmentRule::TYPE_CUSTOM_FIELD_REGEX,
                    EventAssignmentRule::TYPE_INVOICE_ID_CONTAINS,
                    EventAssignmentRule::TYPE_INVOICE_ID_REGEX,
                ])),
            Forms\Components\Toggle::make('case_sensitive')->label('Groß-/Kleinschreibung beachten'),
            Forms\Components\TextInput::make('amount_min')->label('Betrag von')->numeric()
                ->visible(fn (Forms\Get $get) => $get('match_type') === EventAssignmentRule::TYPE_AMOUNT_RANGE),
            Forms\Components\TextInput::make('amount_max')->label('Betrag bis')->numeric()
                ->visible(fn (Forms\Get $get) => $get('match_type') === EventAssignmentRule::TYPE_AMOUNT_RANGE),
            Forms\Components\DateTimePicker::make('date_from')->label('Von')
                ->visible(fn (Forms\Get $get) => $get('match_type') === EventAssignmentRule::TYPE_DATE_RANGE),
            Forms\Components\DateTimePicker::make('date_to')->label('Bis')
                ->visible(fn (Forms\Get $get) => $get('match_type') === EventAssignmentRule::TYPE_DATE_RANGE),
            Forms\Components\Select::make('paypal_account_id')
                ->label('PayPal-Konto')
                ->relationship('paypalAccount', 'name')
                ->visible(fn (Forms\Get $get) => $get('match_type') === EventAssignmentRule::TYPE_PAYPAL_ACCOUNT),
            Forms\Components\TextInput::make('priority')->label('Priorität')->numeric()->default(0)
                ->helperText('Höhere Priorität wird zuerst geprüft.'),
            Forms\Components\Toggle::make('is_active')->label('Aktiv')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('match_type')
            ->columns([
                Tables\Columns\TextColumn::make('match_type')->label('Typ'),
                Tables\Columns\TextColumn::make('pattern')->label('Muster'),
                Tables\Columns\TextColumn::make('priority')->label('Priorität')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->defaultSort('priority', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
