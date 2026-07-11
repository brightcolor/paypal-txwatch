<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Events & Kunden';

    protected static ?string $modelLabel = 'Kunde';

    protected static ?string $pluralModelLabel = 'Kunden';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage-events') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(255),
            Forms\Components\TextInput::make('contact_name')->label('Ansprechpartner')->maxLength(255),
            Forms\Components\TextInput::make('contact_email')->label('Kontakt-E-Mail')->email()->maxLength(255),
            Forms\Components\Toggle::make('is_active')->label('Aktiv')->default(true),
            Forms\Components\Textarea::make('internal_notes')->label('Interne Notizen')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('contact_name')->label('Ansprechpartner'),
                Tables\Columns\TextColumn::make('contact_email')->label('E-Mail'),
                Tables\Columns\TextColumn::make('events_count')->label('Events')->counts('events'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->actions([
                Tables\Actions\Action::make('settlement')
                    ->label('Sammelabrechnung')
                    ->icon('heroicon-o-document-currency-euro')
                    ->visible(fn ($record) => $record->events()->whereHas('transactions')->exists())
                    ->form([
                        Forms\Components\TextInput::make('vat_rate')
                            ->label('MwSt-Satz (Fallback)')
                            ->helperText('Nur für Zahlungen ohne pretix-Verknüpfung.')
                            ->numeric()->suffix('%')->default(19)->minValue(0)->maxValue(100)->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $builder = app(\App\Services\Export\SettlementBuilder::class);
                        $settlement = $builder->persist(
                            $builder->buildForCustomer($record, (float) ($data['vat_rate'] ?? 19)),
                            auth()->user(),
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('Sammelabrechnung erstellt')
                            ->body('Auszahlungsbetrag ' . number_format((float) $settlement->payout, 2, ',', '.') . ' €. Unter Exporte → Abrechnungen verfügbar.')
                            ->success()
                            ->send();
                    }),
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
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
