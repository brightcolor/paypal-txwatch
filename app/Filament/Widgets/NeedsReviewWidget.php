<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TransactionResource;
use App\Models\Transaction;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Zu prüfen"-Inbox on the dashboard: transactions the reconciliation flagged
 * (amount differs from pretix, or no pretix order found) - the short list a
 * human should look at, with one-click jump into the pre-filtered list. Hidden
 * entirely when there is nothing to review.
 */
class NeedsReviewWidget extends BaseWidget
{
    protected static ?string $heading = 'Zu prüfen';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        // Operator concern (reconciliation), and never shown to customers.
        if (auth()->user()?->hasRole('customer') ?? false) {
            return false;
        }

        return static::baseQuery()->exists();
    }

    protected static function baseQuery(): Builder
    {
        return \App\Support\CustomerScope::transactions(
            Transaction::query()->excludingIrrelevant()->currentRevision()
        )
            ->whereIn('reconciliation_status', [
                Transaction::RECONCILIATION_MISMATCH,
                Transaction::RECONCILIATION_UNMATCHED,
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(static::baseQuery()->with(['event', 'pretixOrder'])->latest('transaction_initiation_date'))
            ->paginated([10])
            ->columns([
                TextColumn::make('transaction_initiation_date')->label('Datum')->dateTime('d.m.Y'),
                TextColumn::make('event_ref')->label('Event')
                    ->state(fn (Transaction $r) => $r->event?->displayName() ?? \App\Services\CustomFieldParser::eventReference($r->custom_field))
                    ->limit(24),
                TextColumn::make('custom_field')->label('Bestellnummer')
                    ->formatStateUsing(fn (?string $state) => \App\Services\CustomFieldParser::orderNumber($state)),
                TextColumn::make('gross_amount')->label('Betrag')->money('EUR')->alignEnd()
                    ->color(fn (Transaction $record) => (float) $record->gross_amount < 0 ? 'danger' : 'primary'),
                TextColumn::make('pretix_total')->label('pretix')->alignEnd()
                    ->state(fn (Transaction $r) => $r->pretixOrder ? number_format((float) $r->pretixOrder->total, 2, ',', '.') . ' €' : '–'),
                TextColumn::make('reconciliation_status')->label('Problem')->badge()
                    ->formatStateUsing(fn (?string $state) => $state === Transaction::RECONCILIATION_MISMATCH ? 'Betrag weicht ab' : 'nicht in pretix')
                    ->color(fn (?string $state) => $state === Transaction::RECONCILIATION_MISMATCH ? 'danger' : 'warning'),
            ])
            ->actions([
                Action::make('open')
                    ->label('Ansehen')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Transaction $record) => TransactionResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(),
            ]);
    }
}
