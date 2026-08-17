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

                /*
                 * Assignment and proposal in ONE column, but visibly different.
                 * Two columns would look like two facts; there is only one - what
                 * the recognition made of the purpose - and how sure it is.
                 */
                Tables\Columns\TextColumn::make('pretix_order_code')
                    ->label('pretix-Auftrag')
                    ->badge()
                    ->state(function ($record) {
                        if (filled($record->pretix_order_code)) {
                            return $record->pretix_order_code;
                        }

                        $suggestion = $record->bestSuggestion();

                        // The question mark is the point: this is a proposal, not a
                        // finding, and it must not read like one.
                        return $suggestion ? $suggestion['code'] . ' ?' : null;
                    })
                    ->color(fn ($record) => filled($record->pretix_order_code) ? 'success' : 'warning')
                    ->placeholder('—')
                    ->tooltip(function ($record) {
                        if (filled($record->pretix_order_code)) {
                            $exact = $record->match_method === \App\Services\EnableBanking\PurposeMatcher::EXACT;

                            return $exact
                                ? 'Bestellnummer steht wörtlich im Verwendungszweck.'
                                : 'Erst nach Entfernen von Trenn- und Leerzeichen gefunden.';
                        }

                        $suggestion = $record->bestSuggestion();

                        if (! $suggestion) {
                            return 'Keine offene Bestellnummer erkennbar.';
                        }

                        return sprintf(
                            'VORSCHLAG, nicht zugeordnet: im Zweck steht „%s", die offene Bestellung heisst '
                            . '„%s" – ein Zeichen weicht ab. Betrag %s. Sicherheit %d von 100.',
                            $suggestion['found'],
                            $suggestion['code'],
                            $suggestion['amount_matches'] ? 'passt genau' : 'passt NICHT',
                            $suggestion['score'],
                        );
                    }),

                /*
                 * How it was recognised. Hidden by default - it matters when
                 * something looks wrong, not in everyday use.
                 */
                Tables\Columns\TextColumn::make('match_method')
                    ->label('Erkennung')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        \App\Services\EnableBanking\PurposeMatcher::EXACT => 'wörtlich',
                        \App\Services\EnableBanking\PurposeMatcher::NORMALISED => 'nach Bereinigung',
                        \App\Services\EnableBanking\PurposeMatcher::FUZZY => 'Vorschlag',
                        default => 'nichts',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        \App\Services\EnableBanking\PurposeMatcher::EXACT => 'success',
                        \App\Services\EnableBanking\PurposeMatcher::NORMALISED => 'info',
                        \App\Services\EnableBanking\PurposeMatcher::FUZZY => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

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

                /*
                 * The most useful filter of them all: everything with a proposal
                 * waiting for a decision. That is the work list - assigned entries
                 * need nobody, and entries without any candidate need a look at the
                 * purpose, not a click.
                 */
                Tables\Filters\Filter::make('offene_vorschlaege')
                    ->label('Offene Vorschläge')
                    ->query(fn (Builder $q) => $q
                        ->whereNull('pretix_order_code')
                        ->where('match_method', \App\Services\EnableBanking\PurposeMatcher::FUZZY)),
            ])
            /*
             * THE PROTOCOL, reachable per row. Not a separate page: the question
             * "why does this entry look like this" always arises AT the entry, and a
             * detour through a log list would mean searching for it again.
             */
            ->actions([
                Tables\Actions\Action::make('protokoll')
                    ->label('Protokoll')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->modalHeading('Protokoll dieses Umsatzes')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Schliessen')
                    ->modalContent(fn ($record) => view('filament.bank.journal-protocol', [
                        'entry' => $record,
                        'events' => $record->events,
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnableBankingJournalEntries::route('/'),
        ];
    }
}
