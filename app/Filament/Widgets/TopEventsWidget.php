<?php

namespace App\Filament\Widgets;

use App\Models\Transaction;
use App\Support\CustomerScope;
use Filament\Widgets\TableWidget as BaseWidget;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Top 5 events by revenue in the last 90 days - a quick "what's actually
 * bringing in money" view. Customer-scoped. Hidden when there is no revenue
 * to rank.
 */
class TopEventsWidget extends BaseWidget
{
    protected static ?string $heading = 'Top-Events (90 Tage)';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return static::baseQuery()->exists();
    }

    protected static function baseQuery()
    {
        $since = now()->subDays(90);

        return CustomerScope::transactions(
            Transaction::query()->excludingLedgerEvents()->excludingIrrelevant()
        )
            ->whereNotNull('event_id')
            ->where('transaction_initiation_date', '>=', $since)
            ->leftJoin('events', 'events.id', '=', 'transactions.event_id')
            // Alias event_id as id so Filament has a stable per-row key.
            ->selectRaw('transactions.event_id as id')
            ->selectRaw("COALESCE(NULLIF(events.display_name, ''), events.name, 'Event') as event_name")
            ->selectRaw('count(*) as tx_count')
            ->selectRaw('COALESCE(sum(transactions.gross_amount), 0) as gross')
            ->selectRaw('COALESCE(sum(transactions.net_amount), 0) as net')
            ->groupBy('transactions.event_id', 'event_name')
            ->orderByDesc('gross')
            ->limit(5);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => static::baseQuery())
            ->paginated(false)
            ->columns([
                TextColumn::make('event_name')->label('Event'),
                TextColumn::make('tx_count')->label('Transaktionen')->alignEnd(),
                TextColumn::make('gross')->label('Umsatz')->money('EUR')->alignEnd()
                    ->color('primary'),
                TextColumn::make('net')->label('Nach Gebühren')->money('EUR')->alignEnd()
                    ->color('success'),
            ]);
    }
}
