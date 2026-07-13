<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PretixConnectionResource\Pages;
use App\Jobs\ImportPretixOrdersJob;
use App\Models\PretixConnection;
use App\Services\Pretix\PretixClient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PretixConnectionResource extends Resource
{
    protected static ?string $model = PretixConnection::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'pretix';

    protected static ?string $navigationLabel = 'pretix-Verbindungen';

    protected static ?string $modelLabel = 'pretix-Verbindung';

    protected static ?string $pluralModelLabel = 'pretix-Verbindungen';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage-pretix-connections') ?? false;
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
                    Forms\Components\TextInput::make('base_url')
                        ->label('Basis-URL')
                        ->placeholder('https://pretix.eu')
                        ->helperText('Ohne /api/v1 – z. B. https://pretix.eu oder deine eigene Instanz.')
                        ->required()
                        ->url()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('organizer_slug')
                        ->label('Organizer-Slug')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktiv')
                        ->default(true),
                ]),

            Forms\Components\Section::make('API-Zugang')
                ->description('Wird verschlüsselt gespeichert. pretix: Team-Einstellungen → API-Tokens.')
                ->schema([
                    Forms\Components\TextInput::make('api_token')
                        ->label('API-Token')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context) => $context === 'create')
                        ->dehydrated(fn ($state) => filled($state))
                        ->maxLength(255),
                ]),

            Forms\Components\Section::make('Abrechnung & Import')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('bank_transfer_fee_cents')
                        ->label('Überweisungsgebühr (Cent/Transaktion)')
                        ->numeric()
                        ->default(20)
                        ->required()
                        ->helperText('Wird pro Überweisungs-Bestellung als Gebühr verbucht.'),
                    Forms\Components\Toggle::make('sync_enabled')
                        ->label('Automatischer Import aktiv')
                        ->helperText('Import & Abgleich laufen automatisch alle 30 Minuten.')
                        ->default(true),
                    Forms\Components\Toggle::make('import_paypal_orders')
                        ->label('Auch PayPal-Bestellungen importieren')
                        ->helperText('Standard: aus – PayPal-Zahlungen kommen bereits über den PayPal-Sync (Doppelzählung vermeiden).')
                        ->default(false),
                    Forms\Components\Toggle::make('auto_confirm_bank_transfers')
                        ->label('Banküberweisungen automatisch in pretix bestätigen')
                        ->helperText('Standard: aus. Wenn an, werden eindeutig zugeordnete Kontoeingänge (Betrag exakt, Bestellcode im Zweck) beim Bankabruf sofort in pretix als bezahlt gemeldet (löst Ticket-Versand aus). Aus = nur Vorschlag mit 1-Klick-Bestätigung. Der API-Token braucht dafür das Recht „Bestellungen ändern".')
                        ->default(false),
                ]),

            Forms\Components\Section::make('Webhook (near-realtime Import)')
                ->description('Diese URL in pretix unter Organizer → Webhooks eintragen (Trigger: Bestellungen). Dann wird bei jeder Bestelländerung automatisch importiert – ohne auf den 30-Minuten-Takt zu warten.')
                ->hiddenOn('create')
                ->schema([
                    Forms\Components\TextInput::make('webhook_url')
                        ->label('Webhook-URL')
                        ->disabled()
                        ->dehydrated(false)
                        ->formatStateUsing(fn (?PretixConnection $record) => $record?->webhookUrl()),
                ]),

            Forms\Components\Section::make('Status')
                ->columns(2)
                ->hiddenOn('create')
                ->schema([
                    Forms\Components\Placeholder::make('last_synced_at')
                        ->label('Letzter Import-Versuch')
                        ->content(fn (?PretixConnection $record) => $record?->last_synced_at?->diffForHumans() ?? '–'),
                    Forms\Components\Placeholder::make('last_successful_sync_at')
                        ->label('Letzter erfolgreicher Import')
                        ->content(fn (?PretixConnection $record) => $record?->last_successful_sync_at?->diffForHumans() ?? '–'),
                    Forms\Components\Placeholder::make('last_error')
                        ->label('Letzter Fehler')
                        ->columnSpanFull()
                        ->content(fn (?PretixConnection $record) => $record?->last_error ?? '–'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Refresh while a background import is running so its result appears
            // without a manual reload.
            ->poll('10s')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('base_url')->label('Instanz'),
                Tables\Columns\TextColumn::make('organizer_slug')->label('Organizer'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktiv')->boolean(),
                Tables\Columns\TextColumn::make('bank_transfer_fee_cents')
                    ->label('Überw.-Gebühr')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2, ',', '.') . ' €'),
                Tables\Columns\TextColumn::make('import_status')
                    ->label('Import')
                    ->badge()
                    ->state(fn (PretixConnection $record) => $record->import_running
                        ? 'läuft…'
                        : ($record->last_import_summary ?? '–'))
                    ->color(fn (PretixConnection $record) => $record->import_running ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('last_successful_sync_at')->label('Letzter Import')->dateTime('d.m.Y H:i')->placeholder('–'),
                Tables\Columns\TextColumn::make('last_error')->label('Fehler')->limit(40)->color('danger')->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('testConnection')
                    ->label('Verbindung testen')
                    ->icon('heroicon-o-signal')
                    ->action(function (PretixConnection $record) {
                        $result = (new PretixClient($record))->testConnection();

                        Notification::make()
                            ->title($result['success'] ? 'Verbindung erfolgreich' : 'Verbindung fehlgeschlagen')
                            ->body($result['message'])
                            ->status($result['success'] ? 'success' : 'danger')
                            ->send();
                    }),
                Tables\Actions\Action::make('import')
                    ->label('Import & Abgleich')
                    ->icon('heroicon-o-arrow-path')
                    ->disabled(fn (PretixConnection $record) => $record->import_running)
                    ->requiresConfirmation()
                    ->modalDescription('Lädt alle pretix-Bestellungen und gleicht sie mit den PayPal-Transaktionen ab (PayPal bleibt führend). Läuft im Hintergrund; das Ergebnis erscheint bei der Verbindung.')
                    ->action(function (PretixConnection $record) {
                        // Runs in the queue (not the web request) so large imports can't
                        // hit the PHP-FPM/nginx timeout - see ImportPretixOrdersJob.
                        $record->forceFill(['import_running' => true, 'last_error' => null])->save();
                        ImportPretixOrdersJob::dispatch($record->id);

                        Notification::make()
                            ->title('pretix-Import gestartet')
                            ->body('Der Import läuft im Hintergrund. Ergebnis und Zeitpunkt erscheinen gleich bei der Verbindung (Seite neu laden).')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPretixConnections::route('/'),
            'create' => Pages\CreatePretixConnection::route('/create'),
            'edit' => Pages\EditPretixConnection::route('/{record}/edit'),
        ];
    }
}
