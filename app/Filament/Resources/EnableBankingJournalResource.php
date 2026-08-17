<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EnableBankingJournalResource\Pages;
use App\Models\EnableBankingJournalEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Enable Banking journal: what the bank sent, nothing booked.
 *
 * READ-ONLY BY CONSTRUCTION - no create, no edit, no delete. These rows are a
 * record of an external system's answer; editing them would destroy exactly the
 * evidence they exist for. Deleting is not offered either: an entry that
 * disappears takes the proof with it that a pull once delivered it.
 *
 * WHAT THIS VIEW IS FOR right now: judging, before anything is booked, whether the
 * automation will work. The "pretix-Auftrag" column answers the question the whole
 * later step depends on - is the order code actually in the purpose text? A pull
 * where that column is empty everywhere would book nothing later, and it is better
 * to see that here than after switching the mode.
 */
class EnableBankingJournalResource extends Resource
{
    protected static ?string $model = EnableBankingJournalEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Bank';

    protected static ?string $navigationLabel = 'Bank-Journal (Enable Banking)';

    protected static ?string $modelLabel = 'Journaleintrag';

    protected static ?string $pluralModelLabel = 'Journaleinträge';

    protected static ?int $navigationSort = 17;

    protected static ?string $slug = 'bank-journal';

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

    /** How many entries are still only recorded - shown as a badge in the menu. */
    public static function getNavigationBadge(): ?string
    {
        $open = static::getModel()::query()->whereNull('promoted_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('booked_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('booked_on')
                    ->label('Gebucht')->date('d.m.Y')->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Betrag')
                    ->money('EUR')
                    ->sortable()
                    // Money in green, money out red - the direction is what the eye
                    // looks for first in a bank list.
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('counterparty_name')
                    ->label('Gegenseite')->searchable()->wrap()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('purpose')
                    ->label('Verwendungszweck')
                    ->searchable()
                    // Truncated, with the full text on hover: bank purposes run to
                    // several hundred characters (a Sparkasse fee invoice brings its
                    // whole breakdown along) and would push every other column off
                    // the screen.
                    ->limit(70)
                    ->tooltip(fn ($record) => $record->purpose)
                    ->wrap(),

                /*
                 * Refunds are marked, because in a list of credits a single
                 * negative amount reads as a typo. It is the one kind of debit
                 * that gets recorded at all - and the one that has to reverse a
                 * payment later.
                 */
                Tables\Columns\TextColumn::make('art')
                    ->label('Art')
                    ->badge()
                    ->state(fn ($record) => (float) $record->amount < 0 ? 'Erstattung' : 'Eingang')
                    ->color(fn ($state) => $state === 'Erstattung' ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('pretix_order_code')
                    ->label('pretix-Auftrag')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—')
                    ->tooltip('Diese offene pretix-Bestellnummer steht im Verwendungszweck. Gebucht wird noch nichts.'),

                Tables\Columns\IconColumn::make('promoted_at')
                    ->label('Übernommen')
                    ->boolean()
                    ->tooltip(fn ($record) => $record->promoted_at
                        ? 'In die Kontoumsätze übernommen am ' . $record->promoted_at->format('d.m.Y H:i')
                        : 'Nur aufgezeichnet – wirkt in keinem Bericht und keiner Zuordnung.'),

                Tables\Columns\TextColumn::make('pulled_at')
                    ->label('Abgerufen')->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('bank_ref')
                    ->label('Bankreferenz')->toggleable(isToggledHiddenByDefault: true)->copyable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('pretix_order_code')
                    ->label('Mit pretix-Bestellnummer')
                    ->nullable()
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('pretix_order_code'),
                        false: fn (Builder $q) => $q->whereNull('pretix_order_code'),
                        blank: fn (Builder $q) => $q,
                    ),

                Tables\Filters\TernaryFilter::make('promoted_at')
                    ->label('Übernommen')
                    ->nullable()
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('promoted_at'),
                        false: fn (Builder $q) => $q->whereNull('promoted_at'),
                        blank: fn (Builder $q) => $q,
                    ),

                Tables\Filters\Filter::make('nur_eingaenge')
                    ->label('Nur Eingänge')
                    ->query(fn (Builder $q) => $q->where('amount', '>', 0)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnableBankingJournalEntries::route('/'),
        ];
    }
}
