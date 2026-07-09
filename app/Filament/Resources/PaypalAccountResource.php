<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaypalAccountResource\Pages;
use App\Jobs\SyncPaypalAccountJob;
use App\Models\PaypalAccount;
use App\Models\SyncRun;
use App\Services\PayPal\ConnectionTester;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class PaypalAccountResource extends Resource
{
    protected static ?string $model = PaypalAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'PayPal';

    protected static ?string $navigationLabel = 'PayPal-Konten';

    protected static ?string $modelLabel = 'PayPal-Konto';

    protected static ?string $pluralModelLabel = 'PayPal-Konten';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage-paypal-accounts') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Stammdaten')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Anzeigename')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('mode')
                        ->label('Modus')
                        ->options(['sandbox' => 'Sandbox', 'live' => 'Live'])
                        ->default('sandbox')
                        ->required(),
                    Forms\Components\TextInput::make('default_currency')
                        ->label('Standardwährung')
                        ->maxLength(3)
                        ->placeholder('EUR'),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktiv')
                        ->default(true),
                ]),

            Forms\Components\Section::make('API-Zugangsdaten')
                ->description('Werden verschlüsselt gespeichert. Aus der PayPal Developer Console (REST API App mit "Transaction Search" Berechtigung).')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('client_id')
                        ->label('Client ID')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context) => $context === 'create')
                        ->dehydrated(fn ($state) => filled($state))
                        ->maxLength(255),
                    Forms\Components\TextInput::make('client_secret')
                        ->label('Client Secret')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context) => $context === 'create')
                        ->dehydrated(fn ($state) => filled($state))
                        ->maxLength(255),
                ]),

            Forms\Components\Section::make('Sync-Einstellungen')
                ->columns(3)
                ->schema([
                    Forms\Components\Toggle::make('sync_enabled')
                        ->label('Automatischer Sync aktiv')
                        ->default(true),
                    Forms\Components\TextInput::make('sync_interval_minutes')
                        ->label('Intervall (Minuten)')
                        ->numeric()
                        ->default(15)
                        ->required(),
                    Forms\Components\TextInput::make('lookback_hours')
                        ->label('Rückblick-Puffer (Stunden)')
                        ->numeric()
                        ->helperText('Leer = Standardwert aus Konfiguration (' . config('paypal.default_lookback_hours') . 'h). PayPal liefert Transaktionen bis zu 3h verzögert.')
                        ->nullable(),
                ]),

            Forms\Components\Section::make('Status')
                ->columns(2)
                ->hiddenOn('create')
                ->schema([
                    Forms\Components\Placeholder::make('last_synced_at')
                        ->label('Letzter Sync-Versuch')
                        ->content(fn (?PaypalAccount $record) => $record?->last_synced_at?->diffForHumans() ?? '–'),
                    Forms\Components\Placeholder::make('last_successful_sync_at')
                        ->label('Letzter erfolgreicher Sync')
                        ->content(fn (?PaypalAccount $record) => $record?->last_successful_sync_at?->diffForHumans() ?? '–'),
                    Forms\Components\Placeholder::make('last_error')
                        ->label('Letzter Fehler')
                        ->columnSpanFull()
                        ->content(fn (?PaypalAccount $record) => $record?->last_error ?? '–'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\BadgeColumn::make('mode')
                    ->label('Modus')
                    ->colors(['warning' => 'sandbox', 'success' => 'live']),
                Tables\Columns\IconColumn::make('is_active')->label('Aktiv')->boolean(),
                Tables\Columns\IconColumn::make('sync_enabled')->label('Sync aktiv')->boolean(),
                Tables\Columns\TextColumn::make('sync_interval_minutes')->label('Intervall (Min)')->sortable(),
                Tables\Columns\TextColumn::make('last_synced_at')
                    ->label('Letzter Sync')
                    ->since()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_successful_sync_at')
                    ->label('Letzter Erfolg')
                    ->since()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_error')
                    ->label('Fehler')
                    ->limit(40)
                    ->color('danger')
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('testConnection')
                    ->label('Verbindung testen')
                    ->icon('heroicon-o-signal')
                    ->action(function (PaypalAccount $record) {
                        $result = app(ConnectionTester::class)->test($record);

                        Notification::make()
                            ->title($result['success'] ? 'Verbindung erfolgreich' : 'Verbindung fehlgeschlagen')
                            ->body($result['message'])
                            ->status($result['success'] ? 'success' : 'danger')
                            ->send();
                    }),

                Tables\Actions\Action::make('backfill')
                    ->label('Backfill starten')
                    ->icon('heroicon-o-arrow-path')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Von')->required(),
                        Forms\Components\DatePicker::make('to')->label('Bis')->required(),
                    ])
                    ->action(function (PaypalAccount $record, array $data) {
                        dispatch(new SyncPaypalAccountJob(
                            paypalAccountId: $record->id,
                            start: Carbon::parse($data['from'])->startOfDay()->toIso8601String(),
                            end: Carbon::parse($data['to'])->endOfDay()->toIso8601String(),
                            type: SyncRun::TYPE_BACKFILL,
                            triggeredByUserId: auth()->id(),
                        ));

                        Notification::make()
                            ->title('Backfill eingereiht')
                            ->body('Der Import wird im Hintergrund automatisch in 31-Tage-Blöcke aufgeteilt.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListPaypalAccounts::route('/'),
            'create' => Pages\CreatePaypalAccount::route('/create'),
            'view' => Pages\ViewPaypalAccount::route('/{record}'),
            'edit' => Pages\EditPaypalAccount::route('/{record}/edit'),
        ];
    }
}
