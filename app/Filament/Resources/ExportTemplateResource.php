<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExportTemplateResource\Pages;
use App\Models\ExportTemplate;
use App\Services\Export\ExportColumns;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ExportTemplateResource extends Resource
{
    protected static ?string $model = ExportTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?string $navigationGroup = 'Exporte';

    protected static ?string $modelLabel = 'Export-Vorlage';

    protected static ?string $pluralModelLabel = 'Export-Vorlagen';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage-exports') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Vorlage')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(255),
                    Forms\Components\Select::make('mode')
                        ->label('Modus')
                        ->options([
                            ExportTemplate::MODE_CUSTOMER => 'Kunde (reduziert)',
                            ExportTemplate::MODE_INTERNAL => 'Intern (technisch)',
                        ])
                        ->default(ExportTemplate::MODE_CUSTOMER)
                        ->required(),
                ]),

            Forms\Components\Section::make('Spalten')
                ->description('Reihenfolge per Drag & Drop.')
                ->schema([
                    Forms\Components\Repeater::make('columns')
                        ->label('')
                        ->simple(
                            Forms\Components\Select::make('column')
                                ->options(ExportColumns::LABELS)
                                ->required(),
                        )
                        ->default(ExportTemplate::DEFAULT_COLUMNS)
                        ->reorderable()
                        ->addActionLabel('Spalte hinzufügen'),
                ]),

            Forms\Components\Section::make('Gruppierung & Summen')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('group_by')
                        ->label('Gruppieren nach')
                        ->options([
                            '' => 'Keine Gruppierung',
                            'event' => 'Event',
                            'day' => 'Tag',
                            'week' => 'Woche',
                            'month' => 'Monat',
                            'status' => 'Status',
                            'currency' => 'Währung',
                        ]),
                    Forms\Components\Toggle::make('show_group_sums')->label('Summenzeile je Gruppe')->default(true),
                    Forms\Components\Toggle::make('show_grand_total')->label('Gesamtsumme')->default(true),
                    Forms\Components\TextInput::make('vat_rate')
                        ->label('MwSt-Satz (Standard)')
                        ->helperText('Beim Export überschreibbar. Brutto gilt als MwSt-inklusive.')
                        ->numeric()
                        ->suffix('%')
                        ->default(19)
                        ->minValue(0)
                        ->maxValue(100)
                        ->required(),
                ]),

            Forms\Components\Section::make('Kundenwirksame Darstellung')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')->label('Titel'),
                    Forms\Components\TextInput::make('subtitle')->label('Untertitel'),
                    Forms\Components\Textarea::make('description')->label('Beschreibung')->columnSpanFull(),
                    Forms\Components\Toggle::make('show_event_info')->label('Eventinformationen anzeigen')->default(true),
                    Forms\Components\Toggle::make('mask_pii')->label('Namen/E-Mails maskieren'),
                    Forms\Components\Textarea::make('footer_note')->label('Fußzeilen-Hinweis')->columnSpanFull()
                        ->default('Diese Auswertung basiert auf den zum Exportzeitpunkt lokal synchronisierten PayPal-Transaktionsdaten.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\BadgeColumn::make('mode')->label('Modus'),
                Tables\Columns\TextColumn::make('group_by')->label('Gruppierung'),
                Tables\Columns\TextColumn::make('user.name')->label('Erstellt von'),
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
            'index' => Pages\ListExportTemplates::route('/'),
            'create' => Pages\CreateExportTemplate::route('/create'),
            'edit' => Pages\EditExportTemplate::route('/{record}/edit'),
        ];
    }
}
