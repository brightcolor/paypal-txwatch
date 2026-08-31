<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PretixOrderResource\Pages;
use App\Models\PretixOrder;
use App\Models\PretixOrderLogEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The pretix orders, every one of them, in every status.
 *
 * WHY THIS EXISTS. The orders were imported all along - over a thousand of them -
 * and there was no screen showing them. An order could only be found indirectly:
 * through a transaction that quoted its code, or through a bank entry that
 * recognised one. An order with no money against it yet - which is exactly the case
 * someone goes looking for - appeared nowhere at all, and looked to the user like a
 * failed import.
 *
 * READ-ONLY BY CONSTRUCTION. pretix owns this data; anything editable here would be
 * overwritten by the next import and would meanwhile disagree with the system of
 * record.
 *
 * NO STATUS FILTER ON THE QUERY, and that is the point of the request behind it:
 * cancelled and expired orders are shown like any other. An order missing from a
 * list is indistinguishable from an order missing from the import.
 */
class PretixOrderResource extends Resource
{
    protected static ?string $model = PretixOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'pretix';

    protected static ?string $navigationLabel = 'Bestellungen';

    protected static ?string $modelLabel = 'pretix-Bestellung';

    protected static ?string $pluralModelLabel = 'pretix-Bestellungen';

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'pretix-bestellungen';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('order_datetime', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_code')
                    ->label('Bestellnummer')
                    ->searchable()
                    ->copyable()
                    ->weight('bold')
                    // Deep link into pretix: the next question after finding an order
                    // here is almost always one only pretix can answer.
                    ->url(fn (PretixOrder $record) => $record->url, shouldOpenInNewTab: true)
                    ->color(fn (PretixOrder $record) => filled($record->url) ? 'primary' : null)
                    ->icon(fn (PretixOrder $record) => filled($record->url) ? 'heroicon-m-arrow-top-right-on-square' : null)
                    ->iconPosition(\Filament\Support\Enums\IconPosition::After),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => PretixOrderLogEntry::statusLabel($state))
                    ->color(fn (?string $state) => match ($state) {
                        'p' => 'success',
                        'n' => 'warning',
                        'c' => 'danger',
                        'e' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('event_slug')
                    ->label('Event')->searchable()->sortable()->toggleable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Betrag')->money('EUR')->alignEnd()->sortable(),

                Tables\Columns\TextColumn::make('payment_provider')
                    ->label('Zahlungsart')->badge()->color('gray')->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('order_datetime')
                    ->label('Bestellt am')->dateTime('d.m.Y H:i')->sortable(),

                /*
                 * WHETHER TXWATCH ITSELF REPORTED THE PAYMENT. Only meaningful next to
                 * the status: "offen" plus "gemeldet" is a contradiction worth seeing,
                 * and it is the one case where the automation and pretix disagree.
                 */
                Tables\Columns\TextColumn::make('meldung')
                    ->label('Zahlungsmeldung')
                    ->badge()
                    ->state(fn (PretixOrder $record) => \App\Models\PretixPaymentConfirmation::query()
                        ->where('order_code', $record->order_code)
                        ->where('event_slug', $record->event_slug)
                        ->where('outcome', \App\Models\PretixPaymentConfirmation::OUTCOME_CONFIRMED)
                        ->exists() ? 'gemeldet' : null)
                    ->color('success')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('E-Mail')->searchable()->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Zuletzt aus pretix')->dateTime('d.m.Y H:i')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    // Every status pretix knows, none of them hidden.
                    ->options([
                        'n' => 'offen',
                        'p' => 'bezahlt',
                        'e' => 'abgelaufen',
                        'c' => 'storniert',
                    ]),

                Tables\Filters\SelectFilter::make('event_slug')
                    ->label('Event')
                    ->options(fn () => PretixOrder::query()
                        ->distinct()->orderBy('event_slug')->pluck('event_slug', 'event_slug')->all()),

                Tables\Filters\SelectFilter::make('payment_provider')
                    ->label('Zahlungsart')
                    ->options(fn () => PretixOrder::query()->whereNotNull('payment_provider')
                        ->distinct()->orderBy('payment_provider')->pluck('payment_provider', 'payment_provider')->all()),

                Tables\Filters\Filter::make('nur_offene')
                    ->label('Nur offene')
                    ->query(fn (Builder $query) => $query->where('status', 'n')),

                /*
                 * Orders whose event no longer exists in pretix. Kept as a filter
                 * rather than hidden from the list: leftovers from a deleted or
                 * renamed event are worth finding, not worth silently dropping.
                 */
                Tables\Filters\Filter::make('verwaist')
                    ->label('Verwaist (Event gibt es nicht mehr)')
                    ->query(fn (Builder $query) => $query->whereNotIn(
                        'event_slug',
                        \App\Models\Event::query()->whereNotNull('pretix_event_slug')->pluck('pretix_event_slug'),
                    )),
            ])
            ->actions([
                Tables\Actions\Action::make('verlauf')
                    ->label('Verlauf')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading('Was der Import mit dieser Bestellung gemacht hat')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Schliessen')
                    ->modalContent(fn (PretixOrder $record) => view('filament.pretix.order-history', [
                        'order' => $record,
                        'entries' => PretixOrderLogEntry::query()
                            ->where('order_code', $record->order_code)
                            ->where('event_slug', $record->event_slug)
                            ->orderBy('at')
                            ->orderBy('id')
                            ->get(),
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPretixOrders::route('/'),
        ];
    }
}
