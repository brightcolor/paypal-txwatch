<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BankTransactionResource\Pages;
use App\Models\BankTransaction;
use App\Services\Bank\BankReconciler;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Imported Sparkasse account movements (CAMT.053 / MT940) and their match
 * status against PayPal payouts and pretix transfers. Operator-facing.
 */
class BankTransactionResource extends Resource
{
    protected static ?string $model = BankTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Bank';

    protected static ?string $navigationLabel = 'Kontoumsätze';

    protected static ?string $modelLabel = 'Kontoumsatz';

    protected static ?string $pluralModelLabel = 'Kontoumsätze';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage-transactions') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = BankTransaction::where('reconciliation_status', BankTransaction::STATUS_UNMATCHED)
            ->where('amount', '>', 0)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('valued_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('valued_on')->label('Wertstellung')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('counterparty_name')->label('Gegenseite')->limit(28)->searchable()->placeholder('–'),
                Tables\Columns\TextColumn::make('purpose')->label('Verwendungszweck')->limit(40)->wrap()
                    ->tooltip(fn (BankTransaction $r) => $r->purpose)->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Betrag')->money('EUR')->alignEnd()->sortable()
                    ->color(fn (BankTransaction $r) => (float) $r->amount < 0 ? 'danger' : 'success')
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold),
                Tables\Columns\TextColumn::make('reconciliation_status')->label('Abgleich')->badge()
                    ->formatStateUsing(fn (string $s) => match ($s) {
                        BankTransaction::STATUS_MATCHED => 'zugeordnet',
                        BankTransaction::STATUS_IGNORED => 'ignoriert',
                        default => 'offen',
                    })
                    ->color(fn (string $s) => match ($s) {
                        BankTransaction::STATUS_MATCHED => 'success',
                        BankTransaction::STATUS_IGNORED => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('match_method')->label('Zuordnung über')
                    ->formatStateUsing(fn (?string $s) => match ($s) {
                        BankTransaction::METHOD_PAYOUT => 'PayPal-Auszahlung',
                        BankTransaction::METHOD_PRETIX => 'pretix-Überweisung',
                        BankTransaction::METHOD_MANUAL => 'manuell',
                        default => '–',
                    })->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reconciliation_status')->label('Abgleich')->options([
                    BankTransaction::STATUS_UNMATCHED => 'offen',
                    BankTransaction::STATUS_MATCHED => 'zugeordnet',
                    BankTransaction::STATUS_IGNORED => 'ignoriert',
                ]),
                Tables\Filters\TernaryFilter::make('direction')->label('Richtung')
                    ->placeholder('Alle')->trueLabel('nur Eingänge')->falseLabel('nur Ausgänge')
                    ->queries(
                        true: fn ($q) => $q->where('amount', '>', 0),
                        false: fn ($q) => $q->where('amount', '<', 0),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('view_match')->label('Transaktion')->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (BankTransaction $r) => $r->matched_transaction_id !== null)
                    ->url(fn (BankTransaction $r) => TransactionResource::getUrl('view', ['record' => $r->matched_transaction_id]))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('ignore')->label('Ignorieren')->icon('heroicon-o-eye-slash')->color('gray')
                    ->visible(fn (BankTransaction $r) => $r->reconciliation_status !== BankTransaction::STATUS_IGNORED)
                    ->action(fn (BankTransaction $r) => $r->update(['reconciliation_status' => BankTransaction::STATUS_IGNORED])),
                Tables\Actions\Action::make('unignore')->label('Zurücksetzen')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                    ->visible(fn (BankTransaction $r) => $r->reconciliation_status === BankTransaction::STATUS_IGNORED)
                    ->action(fn (BankTransaction $r) => $r->update([
                        'reconciliation_status' => $r->matched_transaction_id ? BankTransaction::STATUS_MATCHED : BankTransaction::STATUS_UNMATCHED,
                    ])),
            ])
            ->headerActions([
                Tables\Actions\Action::make('rematch')->label('Erneut abgleichen')->icon('heroicon-o-arrow-path')->color('gray')
                    ->action(function () {
                        $n = app(BankReconciler::class)->reconcile();
                        \Filament\Notifications\Notification::make()->title("{$n} neue Zuordnungen")->success()->send();
                    }),
            ])
            ->emptyStateHeading('Noch keine Kontoumsätze')
            ->emptyStateDescription('Importiere einen Sparkassen-Kontoauszug (CAMT.053 oder MT940) über den Button oben.')
            ->emptyStateIcon('heroicon-o-building-library');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankTransactions::route('/'),
        ];
    }
}
