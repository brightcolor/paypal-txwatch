<?php

namespace App\Filament\Widgets;

use App\Models\PaypalAccount;
use App\Services\PayPal\ConnectionTester;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Dashboard "API-Healthcheck": one glance at every account's sync
 * freshness, with a manual connection test action - so a stalled sync
 * (worker down, revoked credentials, ...) is obvious without digging
 * through Sync-Läufe.
 */
class SyncHealthWidget extends BaseWidget
{
    protected static ?string $heading = 'Sync-Gesundheit';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(PaypalAccount::query()->where('is_active', true))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Konto'),
                Tables\Columns\BadgeColumn::make('mode')
                    ->label('Modus')
                    ->colors(['warning' => 'sandbox', 'success' => 'live']),
                Tables\Columns\IconColumn::make('sync_enabled')->label('Sync aktiv')->boolean(),
                Tables\Columns\TextColumn::make('last_successful_sync_at')
                    ->label('Letzter Erfolg')
                    ->since()
                    ->placeholder('nie'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (PaypalAccount $record) => $record->isSyncOverdue() ? 'Warnung' : 'OK')
                    ->badge()
                    ->color(fn (PaypalAccount $record) => $record->isSyncOverdue() ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('last_error')
                    ->label('Letzter Fehler')
                    ->limit(50)
                    ->color('danger'),
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
            ])
            ->paginated(false);
    }
}
