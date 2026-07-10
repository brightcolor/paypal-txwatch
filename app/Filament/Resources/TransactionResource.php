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

    /**
     * Customers only ever see transactions belonging to events of their own
     * customer record; every other role (admin/manager/auditor) sees all.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && $user->hasRole('customer')) {
            $query->whereHas('event', fn (Builder $q) => $q->where('customer_id', $user->customer_id));
        }

        return $query;
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
                ->relationship('event', 'name')
                ->searchable()
                ->preload()
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
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
                Tables\Columns\TextColumn::make('custom_field')
                    ->label('Custom Field')
                    ->searchable()
                    ->toggleable()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('invoice_id')->label('Invoice ID')->searchable()->toggleable(),
                Tables\Columns\BadgeColumn::make('transaction_status')->label('Status'),
                Tables\Columns\TextColumn::make('transaction_event_code')->label('T-Code')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('gross_amount')->label('Brutto')->money(fn ($record) => $record->currency ?? 'EUR')->sortable(),
                Tables\Columns\TextColumn::make('fee_amount')->label('Gebühr')->money(fn ($record) => $record->currency ?? 'EUR')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('net_amount')->label('Netto')->money(fn ($record) => $record->currency ?? 'EUR')->sortable(),
                Tables\Columns\TextColumn::make('currency')->label('Währung')->toggleable(),
                Tables\Columns\TextColumn::make('event.name')->label('Event')->badge()->color(fn ($record) => $record->event_id ? 'success' : 'gray')->default('– nicht zugeordnet –'),
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
                    ->form([
                        Forms\Components\Select::make('event_id')
                            ->label('Event')
                            ->options(fn () => Event::query()->pluck('name', 'id'))
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
                        ->form([
                            Forms\Components\Select::make('event_id')
                                ->label('Event')
                                ->options(fn () => Event::query()->pluck('name', 'id'))
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
                ->label('Custom Field / Volltextsuche')
                ->form([
                    Forms\Components\TextInput::make('value')->label('Suchbegriff'),
                    Forms\Components\Select::make('field')
                        ->label('Feld')
                        ->options([
                            'custom_field' => 'Custom Field',
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

                    $fields = $data['field'] === 'all'
                        ? ['custom_field', 'invoice_id', 'transaction_id', 'payer_name', 'payer_email', 'subject']
                        : [$data['field']];

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
                ->query(fn (Builder $q, array $data) => $q
                    ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('transaction_initiation_date', '>=', $v))
                    ->when($data['until'] ?? null, fn ($q, $v) => $q->whereDate('transaction_initiation_date', '<=', $v))),

            Tables\Filters\Filter::make('amount_range')
                ->label('Betrag')
                ->form([
                    Forms\Components\TextInput::make('min')->label('von')->numeric(),
                    Forms\Components\TextInput::make('max')->label('bis')->numeric(),
                ])
                ->query(fn (Builder $q, array $data) => $q
                    ->when($data['min'] ?? null, fn ($q, $v) => $q->where('gross_amount', '>=', $v))
                    ->when($data['max'] ?? null, fn ($q, $v) => $q->where('gross_amount', '<=', $v))),

            Tables\Filters\SelectFilter::make('currency')
                ->label('Währung')
                ->multiple()
                ->options(fn () => Transaction::query()->distinct()->pluck('currency', 'currency')->filter()->all()),

            Tables\Filters\SelectFilter::make('payment_method_type')
                ->label('Zahlungsart')
                ->multiple()
                ->options(fn () => Transaction::query()->distinct()->pluck('payment_method_type', 'payment_method_type')->filter()->all()),

            Tables\Filters\SelectFilter::make('payer_country_code')
                ->label('Land')
                ->multiple()
                ->options(fn () => Transaction::query()->distinct()->pluck('payer_country_code', 'payer_country_code')->filter()->all()),

            Tables\Filters\SelectFilter::make('transaction_status')
                ->label('Status')
                ->multiple()
                ->options(fn () => Transaction::query()->distinct()->pluck('transaction_status', 'transaction_status')->filter()->all()),

            Tables\Filters\SelectFilter::make('transaction_event_code')
                ->label('T-Code')
                ->multiple()
                ->options(fn () => Transaction::query()->distinct()->pluck('transaction_event_code', 'transaction_event_code')->filter()->all()),

            Tables\Filters\SelectFilter::make('paypal_account_id')
                ->label('PayPal-Konto')
                ->relationship('paypalAccount', 'name'),

            Tables\Filters\SelectFilter::make('event_id')
                ->label('Event')
                ->relationship('event', 'name'),

            Tables\Filters\TernaryFilter::make('has_fee')
                ->label('Gebühren')
                ->placeholder('Alle')
                ->trueLabel('mit Gebühren')
                ->falseLabel('ohne Gebühren')
                ->queries(
                    true: fn (Builder $q) => $q->where('fee_amount', '<>', 0)->whereNotNull('fee_amount'),
                    false: fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('fee_amount')->orWhere('fee_amount', 0)),
                ),

            Tables\Filters\TernaryFilter::make('amount_sign')
                ->label('Betragsrichtung')
                ->placeholder('Alle')
                ->trueLabel('positiv (Einnahmen)')
                // Negative amounts also cover bank withdrawals (T0400/T0403) and fund
                // holds (T2101), not just refunds - see Transaction::LEDGER_ONLY_EVENT_CODES.
                ->falseLabel('negativ (Rückzahlungen, Auszahlungen, Reserven)')
                ->queries(
                    true: fn (Builder $q) => $q->where('gross_amount', '>=', 0),
                    false: fn (Builder $q) => $q->where('gross_amount', '<', 0),
                ),

            Tables\Filters\Filter::make('refunds_only')
                ->label('Nur Rückzahlungen/Reversals')
                ->query(fn (Builder $q) => $q->whereIn('transaction_event_code', Transaction::REFUND_EVENT_CODES)),

            Tables\Filters\TernaryFilter::make('has_custom_field')
                ->label('Custom Field')
                ->placeholder('Alle')
                ->trueLabel('mit Custom Field')
                ->falseLabel('ohne Custom Field')
                ->queries(
                    true: fn (Builder $q) => $q->whereNotNull('custom_field')->where('custom_field', '<>', ''),
                    false: fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('custom_field')->orWhere('custom_field', '')),
                ),

            Tables\Filters\TernaryFilter::make('is_relevant')
                ->label('Relevanz')
                ->placeholder('Alle')
                ->trueLabel('nur relevante')
                ->falseLabel('nur nicht relevante')
                ->queries(
                    true: fn (Builder $q) => $q->excludingIrrelevant(),
                    false: fn (Builder $q) => $q->whereNotNull('marked_irrelevant_at'),
                ),

            Tables\Filters\TernaryFilter::make('is_assigned')
                ->label('Event-Zuordnung')
                ->placeholder('Alle')
                ->trueLabel('zugeordnet')
                ->falseLabel('nicht zugeordnet')
                ->queries(
                    true: fn (Builder $q) => $q->whereNotNull('event_id'),
                    false: fn (Builder $q) => $q->whereNull('event_id'),
                ),

            Tables\Filters\Filter::make('duplicates_only')
                ->label('Nur Mehrfachtreffer (gleiche Transaktions-ID)')
                ->query(fn (Builder $q) => $q->whereIn('transaction_id', function ($sub) {
                    $sub->select('transaction_id')
                        ->from('transactions')
                        ->groupBy('transaction_id')
                        ->havingRaw('count(*) > 1');
                })),
        ];
    }

    private static function applyStringSearch(Builder $q, string $field, string $value, string $mode, bool $caseSensitive): void
    {
        $isPostgres = $q->getConnection()->getDriverName() === 'pgsql';

        if ($mode === 'regex') {
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
