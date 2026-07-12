<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Event;
use App\Models\EventAssignmentRule;
use App\Models\PaypalAccount;
use App\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Transaktionen';

    protected static ?string $modelLabel = 'Transaktion';

    protected static ?string $pluralModelLabel = 'Transaktionen';

    protected static ?int $navigationSort = -1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        // All four business roles carry view-reports; this only shuts out a
        // hypothetical role-less active user (defense in depth - customers are
        // additionally row-scoped in getEloquentQuery()).
        return auth()->user()?->can('view-reports') ?? false;
    }

    /**
     * Customers only ever see transactions belonging to events of their own
     * customer record; every other role (admin/manager/auditor) sees all.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            // Internal PayPal ledger movements (holds/releases, withdrawals) are not
            // customer transactions and stay out of the list entirely - they are shown
            // on the related payment's detail page instead (relatedLedgerTransactions).
            ->excludingLedgerEvents()
            // Eager-load every relationship the table columns touch, otherwise each of
            // the 25 rows/page lazily loads event + paypalAccount + irrelevantMarkedBy +
            // pretixOrder (N+1: dozens of extra queries per page render).
            ->with(['event', 'paypalAccount', 'irrelevantMarkedBy', 'pretixOrder']);

        // Customer users only ever see their own customer's transactions.
        return \App\Support\CustomerScope::transactions($query);
    }

    /**
     * Powers Filament's global search (Cmd+K) across the fields the spec
     * calls out for the "PayPal has no good custom-field search" problem.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['event', 'paypalAccount']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['transaction_id', 'invoice_id', 'custom_field', 'payer_name', 'payer_email', 'subject'];
    }

    public static function getGlobalSearchResultDetails(\Illuminate\Database\Eloquent\Model $record): array
    {
        return [
            'Konto' => $record->paypalAccount?->name,
            'Event' => $record->event?->displayName(),
            'Betrag' => $record->gross_amount . ' ' . $record->currency,
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('event_id')
                ->label('Event')
                // Deactivated events are retired: they never appear in pickers.
                ->relationship('event', 'name', fn ($query) => $query->where('is_active', true))
                ->searchable()
                ->preload()
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Render the page shell immediately and load rows via a follow-up
            // request - the perceived open time drops substantially.
            ->deferLoading()
            // The list never shows the raw PayPal payload; hydrating 25 rows of
            // multi-KB JSON casts per page was a measurable share of render time.
            // Only the table query skips them - the detail page still loads all
            // columns through the resource query.
            ->modifyQueryUsing(function (Builder $query) {
                $heavy = ['raw_payload', 'item_info', 'raw_hash', 'note', 'subject'];
                $columns = \Illuminate\Support\Facades\Cache::remember(
                    'tx_table_columns',
                    now()->addMinutes(10),
                    fn () => array_values(array_diff(
                        \Illuminate\Support\Facades\Schema::getColumnListing('transactions'),
                        $heavy,
                    )),
                );

                return $query->select(array_map(fn (string $c) => "transactions.{$c}", $columns));
            })
            ->defaultSort('transaction_initiation_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('transaction_initiation_date')
                    ->label('Datum')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('transaction_id')
                    ->label('Transaktions-ID')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('payer_name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('payer_email')->label('E-Mail')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('event_ref')
                    ->label('Event')
                    // Real pretix event name once assigned; the parsed short code from
                    // the order number as fallback. Truncated in the table, full name
                    // on hover.
                    ->state(fn (Transaction $record) => $record->event?->displayName()
                        ?? \App\Services\CustomFieldParser::eventReference($record->custom_field))
                    ->limit(25)
                    ->tooltip(fn (Transaction $record) => $record->event?->displayName()
                        ?? \App\Services\CustomFieldParser::eventReference($record->custom_field))
                    ->toggleable()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('custom_field')
                    ->label('Bestellnummer')
                    ->formatStateUsing(fn (?string $state) => \App\Services\CustomFieldParser::orderNumber($state))
                    ->url(fn (Transaction $record) => $record->pretixOrderUrl(), shouldOpenInNewTab: true)
                    ->color(fn (Transaction $record) => $record->pretixOrderUrl() ? 'primary' : null)
                    // External-link icon signals "opens in a new window". No ->copyable()
                    // here: its click handler sits inside the <a> and swallows the
                    // navigation click (copy stays available on the detail page).
                    ->icon(fn (Transaction $record) => $record->pretixOrderUrl() ? 'heroicon-m-arrow-top-right-on-square' : null)
                    ->iconPosition(\Filament\Support\Enums\IconPosition::After)
                    ->tooltip(fn (Transaction $record) => $record->pretixOrderUrl() ? 'Bestellung in pretix öffnen (neues Fenster)' : null)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reconciliation_status')
                    ->label('pretix-Abgleich')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        Transaction::RECONCILIATION_MATCHED => 'abgeglichen',
                        Transaction::RECONCILIATION_MISMATCH => 'Betrag weicht ab',
                        Transaction::RECONCILIATION_UNMATCHED => 'nicht in pretix',
                        default => '–',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        Transaction::RECONCILIATION_MATCHED => 'success',
                        Transaction::RECONCILIATION_MISMATCH => 'danger',
                        Transaction::RECONCILIATION_UNMATCHED => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (Transaction $record) => $record->pretixOrder
                        ? 'pretix-Summe: ' . number_format((float) $record->pretixOrder->total, 2, ',', '.') . ' ' . ($record->pretixOrder->currency ?? '')
                        : null)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('invoice_id')->label('Invoice ID')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Art')
                    ->badge()
                    ->state(fn (Transaction $record) => $record->typeLabel())
                    ->color(fn (string $state) => match ($state) {
                        'Zahlung' => 'success',
                        'Rückzahlung/Storno' => 'danger',
                        'Auszahlung' => 'info',
                        'Reserve/Hold' => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (Transaction $record) => $record->transaction_event_code),
                Tables\Columns\TextColumn::make('transaction_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'S' => 'bezahlt',
                        'P' => 'ausstehend',
                        'D' => 'abgelehnt',
                        'V' => 'storniert',
                        default => $state ?? '–',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'S' => 'success',
                        'P' => 'warning',
                        'D', 'V' => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('transaction_event_code')->label('T-Code')->toggleable(isToggledHiddenByDefault: true),
                // "Brutto/Netto" are tax-law terms and stay reserved for the VAT
                // breakdown in exports; payment amounts use neutral wording.
                // App-wide money color scheme: Betrag blue, Gebühr red (when
                // charged), Nach Gebühren green - negatives always red.
                Tables\Columns\TextColumn::make('gross_amount')
                    ->label('Betrag')
                    ->money(fn ($record) => $record->currency ?? 'EUR')
                    ->alignEnd()
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold)
                    ->color(fn ($record) => (float) $record->gross_amount < 0 ? 'danger' : 'primary')
                    ->sortable(),
                Tables\Columns\TextColumn::make('fee_amount')
                    ->label('Gebühr')
                    ->money(fn ($record) => $record->currency ?? 'EUR')
                    ->alignEnd()
                    // Like the report tables: charged fees (negative) in red.
                    ->color(fn ($record) => (float) $record->fee_amount < 0 ? 'danger' : 'gray')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('net_amount')
                    ->label('Nach Gebühren')
                    ->money(fn ($record) => $record->currency ?? 'EUR')
                    ->alignEnd()
                    ->color(fn ($record) => (float) $record->net_amount < 0 ? 'danger' : 'success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')->label('Währung')->toggleable(),
                // Redundant with the combined "Event" column above now that pretix
                // auto-assignment fills it; kept as an opt-in column for checking the
                // formal assignment state.
                Tables\Columns\TextColumn::make('event.name')
                    ->label('Event (zugeordnet)')
                    ->badge()
                    ->limit(25)
                    ->tooltip(fn (Transaction $record) => $record->event?->displayName())
                    ->color(fn ($record) => $record->event_id ? 'success' : 'gray')
                    ->default('– nicht zugeordnet –')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('paypalAccount.name')->label('PayPal-Konto')->toggleable(),
                Tables\Columns\TextColumn::make('marked_irrelevant_at')
                    ->label('Relevanz')
                    ->badge()
                    ->state(fn (Transaction $record) => $record->isIrrelevant() ? 'Nicht relevant' : 'Relevant')
                    ->color(fn (Transaction $record) => $record->isIrrelevant() ? 'danger' : 'success')
                    ->tooltip(fn (Transaction $record) => $record->isIrrelevant()
                        ? "Grund: {$record->irrelevant_reason}\nVon: {$record->irrelevantMarkedBy?->name} am {$record->marked_irrelevant_at?->format('d.m.Y H:i')}"
                        : null),
            ])
            ->filters(static::filters(), layout: Tables\Enums\FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(3)
            ->persistFiltersInSession()
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('assignEvent')
                    ->label('Event zuweisen')
                    ->icon('heroicon-o-tag')
                    // Write action: viewers (customer/auditor) must not reassign
                    // revenue between events (audit 2026-07-12).
                    ->visible(fn () => auth()->user()?->can('manage-transactions') ?? false)
                    ->form([
                        Forms\Components\Select::make('event_id')
                            ->label('Event')
                            ->options(fn () => Event::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Transaction $record, array $data) {
                        $record->update([
                            'event_id' => $data['event_id'],
                            'assignment_method' => 'manual',
                            'assignment_rule_id' => null,
                            'assigned_at' => now(),
                        ]);

                        Notification::make()->title('Event zugewiesen')->success()->send();
                    }),

                Tables\Actions\Action::make('markIrrelevant')
                    ->label('Als nicht relevant markieren')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (Transaction $record) => ! $record->isIrrelevant() && (auth()->user()?->can('manage-transactions') ?? false))
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Grund')
                            ->required()
                            ->helperText('Wird unveränderlich im Audit-Log festgehalten.'),
                    ])
                    ->action(function (Transaction $record, array $data) {
                        $record->markIrrelevant(auth()->user(), $data['reason']);

                        Notification::make()
                            ->title('Transaktion als nicht relevant markiert')
                            ->body('Wird ab sofort aus Dashboard/Berichten ausgeschlossen. Die Transaktion selbst bleibt vollständig erhalten.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('markRelevant')
                    ->label('Als relevant markieren')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->visible(fn (Transaction $record) => $record->isIrrelevant() && (auth()->user()?->can('manage-transactions') ?? false))
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Grund für die Wiederherstellung')
                            ->required()
                            ->helperText('Wird unveränderlich im Audit-Log festgehalten.'),
                    ])
                    ->action(function (Transaction $record, array $data) {
                        $record->markRelevant(auth()->user(), $data['reason']);

                        Notification::make()->title('Transaktion wieder als relevant markiert')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('assignEventBulk')
                        ->label('Event zuweisen')
                        ->icon('heroicon-o-tag')
                        ->visible(fn () => auth()->user()?->can('manage-transactions') ?? false)
                        ->form([
                            Forms\Components\Select::make('event_id')
                                ->label('Event')
                                ->options(fn () => Event::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (\Illuminate\Support\Collection $records, array $data) {
                            $records->each->update([
                                'event_id' => $data['event_id'],
                                'assignment_method' => 'manual',
                                'assignment_rule_id' => null,
                                'assigned_at' => now(),
                            ]);
                        }),

                    Tables\Actions\BulkAction::make('markIrrelevantBulk')
                        ->label('Als nicht relevant markieren')
                        ->icon('heroicon-o-eye-slash')
                        ->color('danger')
                        ->visible(fn () => auth()->user()?->can('manage-transactions') ?? false)
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Grund')
                                ->required()
                                ->helperText('Wird für jede ausgewählte Transaktion einzeln, unveränderlich im Audit-Log festgehalten.'),
                        ])
                        ->action(function (\Illuminate\Support\Collection $records, array $data) {
                            $records->each(fn (Transaction $record) => $record->isIrrelevant()
                                ? null
                                : $record->markIrrelevant(auth()->user(), $data['reason']));
                        }),
                ]),
            ]);
    }

    /**
     * Every filter the spec calls for: custom-field string search (with
     * match-type + case sensitivity), date/amount ranges, status/currency/
     * account/event pickers, refund and assignment-gap views, duplicate view.
     */
    protected static function filters(): array
    {
        return [
            Tables\Filters\Filter::make('custom_field_search')
                ->label('Bestellnummer / Volltextsuche')
                ->form([
                    Forms\Components\TextInput::make('value')->label('Suchbegriff'),
                    Forms\Components\Select::make('field')
                        ->label('Feld')
                        ->options([
                            'custom_field' => 'Bestellnummer',
                            'invoice_id' => 'Invoice ID',
                            'transaction_id' => 'Transaktions-ID',
                            'payer_name' => 'Name',
                            'payer_email' => 'E-Mail',
                            'subject' => 'Betreff/Notiz',
                            'all' => 'Alle oben genannten (Volltext)',
                        ])
                        ->default('custom_field'),
                    Forms\Components\Select::make('mode')
                        ->label('Suchart')
                        ->options([
                            'contains' => 'enthält',
                            'starts_with' => 'beginnt mit',
                            'ends_with' => 'endet mit',
                            'exact' => 'exakt',
                            'regex' => 'Regex (Admin)',
                        ])
                        ->default('contains'),
                    Forms\Components\Toggle::make('case_sensitive')->label('Groß-/Kleinschreibung beachten')->default(false),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    if (blank($data['value'] ?? null)) {
                        return $query;
                    }

                    $fields = ($data['field'] ?? null) === 'all'
                        ? self::SEARCHABLE_FIELDS
                        : [$data['field'] ?? 'custom_field'];

                    return $query->where(function (Builder $q) use ($fields, $data) {
                        foreach ($fields as $field) {
                            static::applyStringSearch($q, $field, $data['value'], $data['mode'] ?? 'contains', (bool) ($data['case_sensitive'] ?? false));
                        }
                    });
                })
                ->indicateUsing(fn (array $data) => filled($data['value'] ?? null) ? "Suche: \"{$data['value']}\"" : null),

            Tables\Filters\Filter::make('date_range')
                ->label('Zeitraum')
                ->form([
                    Forms\Components\DatePicker::make('from')->label('Von'),
                    Forms\Components\DatePicker::make('until')->label('Bis'),
                ])
                // NOTE: Filament injects closure args BY NAME - the query param
                // must be called $query (a different name resolves to a model-less
                // builder from the container -> "undefined method" 500s).
                ->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('transaction_initiation_date', '>=', $v))
                    ->when($data['until'] ?? null, fn ($q, $v) => $q->whereDate('transaction_initiation_date', '<=', $v))),

            Tables\Filters\Filter::make('amount_range')
                ->label('Betrag')
                ->form([
                    Forms\Components\TextInput::make('min')->label('von')->numeric(),
                    Forms\Components\TextInput::make('max')->label('bis')->numeric(),
                ])
                ->query(fn (Builder $query, array $data) => $query
                    ->when($data['min'] ?? null, fn ($q, $v) => $q->where('gross_amount', '>=', $v))
                    ->when($data['max'] ?? null, fn ($q, $v) => $q->where('gross_amount', '<=', $v))),

            Tables\Filters\SelectFilter::make('currency')
                ->label('Währung')
                ->multiple()
                ->options(fn () => static::distinctColumnOptions('currency')),

            Tables\Filters\SelectFilter::make('payment_method_type')
                ->label('Zahlungsart')
                ->multiple()
                ->options(fn () => static::distinctColumnOptions('payment_method_type')),

            Tables\Filters\SelectFilter::make('payer_country_code')
                ->label('Land')
                ->multiple()
                ->options(fn () => static::distinctColumnOptions('payer_country_code')),

            Tables\Filters\SelectFilter::make('transaction_status')
                ->label('Status')
                ->multiple()
                ->options(fn () => static::distinctColumnOptions('transaction_status')),

            Tables\Filters\SelectFilter::make('transaction_event_code')
                ->label('T-Code')
                ->multiple()
                ->options(fn () => static::distinctColumnOptions('transaction_event_code')),

            Tables\Filters\SelectFilter::make('paypal_account_id')
                ->label('PayPal-Konto')
                ->relationship('paypalAccount', 'name'),

            Tables\Filters\SelectFilter::make('event_id')
                ->label('Event')
                // Customers only see their own events in the dropdown.
                ->relationship('event', 'name', fn ($query) => \App\Support\CustomerScope::byCustomerId($query->where('is_active', true))),

            Tables\Filters\TernaryFilter::make('revisions')
                ->label('Revisionen')
                ->placeholder('Nur aktuelle (Standard)')
                ->trueLabel('Alle Revisionen anzeigen')
                ->falseLabel('Nur aktuelle')
                // Default: hide superseded revisions - they are history rows and
                // would visually double every updated payment.
                ->queries(
                    true: fn (Builder $query) => $query,
                    false: fn (Builder $query) => $query->whereNull('superseded_at'),
                    blank: fn (Builder $query) => $query->whereNull('superseded_at'),
                ),

            Tables\Filters\TernaryFilter::make('has_fee')
                ->label('Gebühren')
                ->placeholder('Alle')
                ->trueLabel('mit Gebühren')
                ->falseLabel('ohne Gebühren')
                ->queries(
                    true: fn (Builder $query) => $query->where('fee_amount', '<>', 0)->whereNotNull('fee_amount'),
                    false: fn (Builder $query) => $query->where(fn ($q) => $q->whereNull('fee_amount')->orWhere('fee_amount', 0)),
                ),

            Tables\Filters\TernaryFilter::make('amount_sign')
                ->label('Betragsrichtung')
                ->placeholder('Alle')
                ->trueLabel('positiv (Einnahmen)')
                // Negative amounts also cover bank withdrawals (T0400/T0403) and fund
                // holds (T2101), not just refunds - see Transaction::LEDGER_ONLY_PREFIXES.
                ->falseLabel('negativ (Rückzahlungen, Auszahlungen, Reserven)')
                ->queries(
                    true: fn (Builder $query) => $query->where('gross_amount', '>=', 0),
                    false: fn (Builder $query) => $query->where('gross_amount', '<', 0),
                ),

            Tables\Filters\SelectFilter::make('art')
                ->label('Art')
                ->options(function () {
                    // Ledger types (Auszahlung, Reserve/Hold) are excluded from the
                    // list entirely, so they are no filter options either.
                    $labels = collect(Transaction::TYPE_PREFIX_LABELS)
                        ->except(Transaction::LEDGER_ONLY_PREFIXES)
                        ->unique()
                        ->values();

                    return $labels->combine($labels)->all() + ['Sonstige' => 'Sonstige'];
                })
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->ofType($data['value'])
                    : $query),

            Tables\Filters\SelectFilter::make('reconciliation_status')
                ->label('pretix-Abgleich')
                ->options([
                    Transaction::RECONCILIATION_MATCHED => 'abgeglichen',
                    Transaction::RECONCILIATION_MISMATCH => 'Betrag weicht ab',
                    Transaction::RECONCILIATION_UNMATCHED => 'nicht in pretix',
                ]),

            Tables\Filters\Filter::make('refunds_only')
                ->label('Nur Rückzahlungen/Reversals')
                ->query(fn (Builder $query) => $query->refunds()),

            Tables\Filters\TernaryFilter::make('has_custom_field')
                ->label('Bestellnummer')
                ->placeholder('Alle')
                ->trueLabel('mit Bestellnummer')
                ->falseLabel('ohne Bestellnummer')
                ->queries(
                    true: fn (Builder $query) => $query->whereNotNull('custom_field')->where('custom_field', '<>', ''),
                    false: fn (Builder $query) => $query->where(fn ($q) => $q->whereNull('custom_field')->orWhere('custom_field', '')),
                ),

            Tables\Filters\TernaryFilter::make('is_relevant')
                ->label('Relevanz')
                ->placeholder('Alle')
                ->trueLabel('nur relevante')
                ->falseLabel('nur nicht relevante')
                ->queries(
                    true: fn (Builder $query) => $query->excludingIrrelevant(),
                    false: fn (Builder $query) => $query->whereNotNull('marked_irrelevant_at'),
                ),

            Tables\Filters\TernaryFilter::make('is_assigned')
                ->label('Event-Zuordnung')
                ->placeholder('Alle')
                ->trueLabel('zugeordnet')
                ->falseLabel('nicht zugeordnet')
                ->queries(
                    true: fn (Builder $query) => $query->whereNotNull('event_id'),
                    false: fn (Builder $query) => $query->whereNull('event_id'),
                ),

            Tables\Filters\Filter::make('duplicates_only')
                ->label('Nur Mehrfachtreffer (gleiche Transaktions-ID)')
                ->query(fn (Builder $query) => $query->whereIn('transaction_id', function ($sub) {
                    $sub->select('transaction_id')
                        ->from('transactions')
                        ->groupBy('transaction_id')
                        ->havingRaw('count(*) > 1');
                })),
        ];
    }

    /**
     * Distinct non-null values of a column for a SelectFilter, cached briefly.
     * Without the cache every table render re-runs a DISTINCT scan per filter
     * (five of them), which is a noticeable share of the page's DB time. A new
     * value only needs to appear in the filter after the next sync, so a short
     * TTL is fine.
     *
     * @return array<string, string>
     */
    private static function distinctColumnOptions(string $column): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "tx_filter_options_{$column}",
            now()->addMinutes(10),
            fn () => Transaction::query()->distinct()->pluck($column, $column)->filter()->all(),
        );
    }

    /** Columns the custom-field/full-text search may target. */
    private const SEARCHABLE_FIELDS = ['custom_field', 'invoice_id', 'transaction_id', 'payer_name', 'payer_email', 'subject'];

    private static function applyStringSearch(Builder $q, string $field, string $value, string $mode, bool $caseSensitive): void
    {
        // $field is interpolated into raw SQL below, so it MUST come from the
        // fixed allow-list - the value in the request cannot be trusted just
        // because the UI is a <select> (Livewire state is client-controllable).
        if (! in_array($field, self::SEARCHABLE_FIELDS, true)) {
            return;
        }

        $isPostgres = $q->getConnection()->getDriverName() === 'pgsql';

        if ($mode === 'regex') {
            // Skip an un-compilable pattern instead of letting the DB raise and
            // 500 the whole table. (@ silences the warning on a bad pattern.)
            if (@preg_match('/' . str_replace('/', '\/', $value) . '/', '') === false) {
                return;
            }

            $operator = $isPostgres ? ($caseSensitive ? '~' : '~*') : 'REGEXP';
            $q->orWhereRaw("{$field} {$operator} ?", [$value]);

            return;
        }

        $pattern = match ($mode) {
            'starts_with' => "{$value}%",
            'ends_with' => "%{$value}",
            'exact' => $value,
            default => "%{$value}%",
        };

        if ($mode === 'exact') {
            $caseSensitive
                ? $q->orWhereRaw("{$field} = ? COLLATE \"C\"", [$pattern])
                : $q->orWhereRaw("LOWER({$field}) = LOWER(?)", [$pattern]);

            return;
        }

        // Postgres LIKE is case-sensitive by default and ILIKE is the
        // case-insensitive variant; SQLite's LIKE is already
        // case-insensitive for ASCII, which is fine for local/testing use.
        $operator = $isPostgres && ! $caseSensitive ? 'ilike' : 'like';
        $q->orWhere($field, $operator, $pattern);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
            'view' => Pages\ViewTransaction::route('/{record}'),
        ];
    }
}
