<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EventResource\Pages;
use App\Filament\Resources\EventResource\RelationManagers;
use App\Models\Event;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Events & Kunden';

    protected static ?string $modelLabel = 'Event';

    protected static ?string $pluralModelLabel = 'Events';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage-events') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Event')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('Kunde/Veranstalter')
                        ->relationship('customer', 'name')
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->required(),
                        ]),
                    Forms\Components\TextInput::make('name')->label('Interner Name')->required()->maxLength(255),
                    Forms\Components\TextInput::make('display_name')->label('Anzeigename für PDF')->maxLength(255)
                        ->helperText('Leer = interner Name wird verwendet.'),
                    Forms\Components\DatePicker::make('event_date')->label('Eventdatum'),
                    Forms\Components\TextInput::make('venue')->label('Veranstaltungsort')->maxLength(255),
                    Forms\Components\TextInput::make('contact_person')->label('Ansprechpartner')->maxLength(255),
                    Forms\Components\Toggle::make('is_active')->label('Aktiv')->default(true),
                ]),

            Forms\Components\Section::make('PDF-Darstellung')
                ->columns(1)
                ->schema([
                    Forms\Components\FileUpload::make('logo_path')->label('Logo')->image()->directory('event-logos'),
                    Forms\Components\Textarea::make('short_description')->label('Kurzbeschreibung')->rows(2),
                    Forms\Components\Textarea::make('pdf_footer')->label('PDF-Fußzeile')->rows(2),
                    Forms\Components\Textarea::make('legal_notice')->label('Rechtliche Hinweise')->rows(2),
                ]),

            Forms\Components\Section::make('Intern')
                ->schema([
                    Forms\Components\Textarea::make('internal_notes')->label('Interne Notizen')->rows(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('customer.name')->label('Kunde')->searchable(),
                Tables\Columns\TextColumn::make('event_date')->label('Datum')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('venue')->label('Ort'),
                Tables\Columns\TextColumn::make('transactions_count')->label('Transaktionen')->counts('transactions'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Alle')
                    ->trueLabel('nur aktive')
                    ->falseLabel('nur deaktivierte'),
            ])
            ->actions([
                Tables\Actions\Action::make('settlement')
                    ->label('Abrechnung erstellen')
                    ->icon('heroicon-o-document-currency-euro')
                    ->visible(fn (Event $record) => $record->transactions()->exists())
                    ->form([
                        Forms\Components\TextInput::make('vat_rate')
                            ->label('MwSt-Satz (Fallback)')
                            ->helperText('Nur für Zahlungen ohne pretix-Verknüpfung; verknüpfte nutzen die echte MwSt aus pretix.')
                            ->numeric()
                            ->suffix('%')
                            ->default(19)
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                    ])
                    ->action(function (Event $record, array $data) {
                        $settlement = app(\App\Services\Export\SettlementBuilder::class)
                            ->build($record, (float) ($data['vat_rate'] ?? 19));
                        $pdf = app(\App\Services\Export\PdfRenderer::class)
                            ->render($settlement, 'exports.settlement');

                        $filename = 'abrechnung-' . \Illuminate\Support\Str::slug($record->displayName())
                            . '-' . now()->format('Ymd-His') . '.pdf';

                        return response()->streamDownload(fn () => print ($pdf), $filename);
                    }),
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn ($record) => $record->is_active ? 'Deaktivieren' : 'Aktivieren')
                    ->icon(fn ($record) => $record->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color(fn ($record) => $record->is_active ? 'gray' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription('Deaktivierte Events tauchen in keiner Auswahlliste mehr auf und erhalten keine automatischen Zuweisungen. Bestehende Zuordnungen bleiben erhalten.')
                    ->action(fn ($record) => $record->update(['is_active' => ! $record->is_active])),
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
        return [
            RelationManagers\AssignmentRulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEvents::route('/'),
            'create' => Pages\CreateEvent::route('/create'),
            'edit' => Pages\EditEvent::route('/{record}/edit'),
        ];
    }
}
