<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PretixPaymentConfirmationResource\Pages;
use App\Models\PretixPaymentConfirmation;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Why an order was marked paid - and why another one was not.
 *
 * READ-ONLY BY CONSTRUCTION: no create, no edit, no delete. These rows exist so a
 * write into someone else's order can be checked afterwards, and a record that can
 * be corrected is not a record.
 *
 * REFUSALS ARE LISTED ALONGSIDE CONFIRMATIONS. "The automation did nothing" is the
 * harder complaint to investigate, and until this list existed the answer was
 * overwritten by the next run.
 */
class PretixPaymentConfirmationResource extends Resource
{
    protected static ?string $model = PretixPaymentConfirmation::class;

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationGroup = 'pretix';

    protected static ?string $navigationLabel = 'Zahlungsmeldungen';

    protected static ?string $modelLabel = 'Zahlungsmeldung';

    protected static ?string $pluralModelLabel = 'Zahlungsmeldungen';

    protected static ?int $navigationSort = 24;

    protected static ?string $slug = 'zahlungsmeldungen';

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
            ->defaultSort('at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('at')
                    ->label('Wann')->dateTime('d.m.Y H:i')->sortable(),

                Tables\Columns\TextColumn::make('outcome')
                    ->label('Ergebnis')
                    ->badge()
                    ->formatStateUsing(fn ($record) => $record->outcomeLabel())
                    ->color(fn (?string $state) => match ($state) {
                        PretixPaymentConfirmation::OUTCOME_CONFIRMED => 'success',
                        PretixPaymentConfirmation::OUTCOME_FAILED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('order_code')
                    ->label('Bestellung')->searchable()->copyable()->placeholder('—'),

                Tables\Columns\TextColumn::make('event_slug')
                    ->label('Event')->searchable()->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Betrag')->money('EUR')->alignEnd()->sortable(),

                /*
                 * THE REASON IN FULL, not a code. This column is the entire point of
                 * the list: whoever opens it wants the sentence, not a key they then
                 * have to look up somewhere else.
                 */
                Tables\Columns\TextColumn::make('reason')
                    ->label('Begründung')
                    ->state(fn ($record) => $record->reasonText())
                    ->wrap(),

                Tables\Columns\TextColumn::make('automatic')
                    ->label('Ausgelöst durch')
                    ->badge()
                    ->state(fn ($record) => $record->automatic ? 'Automatik' : ($record->user?->name ?? 'von Hand'))
                    ->color(fn ($record) => $record->automatic ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('source')
                    ->label('Quelle')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        PretixPaymentConfirmation::SOURCE_JOURNAL => 'Bankabruf',
                        default => 'Kontoumsatz',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('nur_gemeldet')
                    ->label('Nur gemeldete')
                    ->query(fn (Builder $query) => $query
                        ->where('outcome', PretixPaymentConfirmation::OUTCOME_CONFIRMED)),

                /*
                 * A failure is pretix refusing; a refusal is TxWatch declining. Two
                 * different things to look into, so two different filters.
                 */
                Tables\Filters\Filter::make('nur_fehlgeschlagen')
                    ->label('Nur fehlgeschlagene')
                    ->query(fn (Builder $query) => $query
                        ->where('outcome', PretixPaymentConfirmation::OUTCOME_FAILED)),

                Tables\Filters\Filter::make('nur_automatik')
                    ->label('Nur Automatik')
                    ->query(fn (Builder $query) => $query->where('automatic', true)),
            ])
            ->actions([
                Tables\Actions\Action::make('beleg')
                    ->label('Nachweis')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->modalHeading('Warum wurde so entschieden?')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Schliessen')
                    ->modalContent(fn ($record) => view('filament.pretix.payment-confirmation', [
                        'record' => $record,
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPretixPaymentConfirmations::route('/'),
        ];
    }
}
