<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SettlementResource\Pages;
use App\Models\Settlement;
use App\Services\Export\PdfRenderer;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class SettlementResource extends Resource
{
    protected static ?string $model = Settlement::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-euro';

    protected static ?string $navigationGroup = 'Exporte';

    protected static ?string $navigationLabel = 'Abrechnungen';

    protected static ?string $modelLabel = 'Abrechnung';

    protected static ?string $pluralModelLabel = 'Abrechnungen';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        // Managers/admins manage all; customers may read their own settlements.
        return (bool) ($user?->can('manage-exports') || $user?->hasRole('customer'));
    }

    public static function canCreate(): bool
    {
        // Settlements are created from an event / customer, never blank.
        return false;
    }

    /** Whether the current user may change settlements (mark paid / reopen). */
    protected static function canManage(): bool
    {
        return auth()->user()?->can('manage-exports') ?? false;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Customers only ever see their own customer's settlements.
        return \App\Support\CustomerScope::byCustomerId(parent::getEloquentQuery());
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canManage()) {
            return null;
        }

        $open = Settlement::where('status', Settlement::STATUS_OPEN)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Abrechnung')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('period_from')->label('Zeitraum')
                    ->formatStateUsing(fn (Settlement $r) => optional($r->period_from)->format('d.m.Y') . ' – ' . optional($r->period_to)->format('d.m.Y')),
                Tables\Columns\TextColumn::make('payout')->label('Auszahlung')->money('EUR')->alignEnd()
                    ->weight(\Filament\Support\Enums\FontWeight::Bold)
                    // Payout = the "Nach Gebühren" figure -> green (red if negative).
                    ->color(fn (Settlement $record) => (float) $record->payout < 0 ? 'danger' : 'success')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Summe')->money('EUR')),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => $state === Settlement::STATUS_PAID ? 'ausgezahlt' : 'offen')
                    ->color(fn (string $state) => $state === Settlement::STATUS_PAID ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('paid_at')->label('Ausgezahlt am')->dateTime('d.m.Y')->placeholder('–'),
                Tables\Columns\IconColumn::make('sent_at')->label('Versendet')->boolean()
                    ->tooltip(fn (Settlement $r) => $r->sent_at ? ('an ' . $r->sent_to . ' am ' . $r->sent_at->format('d.m.Y H:i')) : null)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('createdBy.name')->label('Erstellt von')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Erstellt')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Status')->options([
                    Settlement::STATUS_OPEN => 'offen',
                    Settlement::STATUS_PAID => 'ausgezahlt',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (Settlement $record) {
                        $pdf = app(PdfRenderer::class)->render($record->pdfData(), 'exports.settlement');
                        $name = 'abrechnung-' . Str::slug($record->title) . '-' . $record->created_at->format('Ymd') . '.pdf';

                        return response()->streamDownload(fn () => print ($pdf), $name);
                    }),
                Tables\Actions\Action::make('sendMail')
                    ->label('Per E-Mail senden')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    // Operator-only, and only once SMTP is actually configured.
                    ->visible(fn () => static::canManage() && \App\Models\MailSetting::current()->isConfigured())
                    ->form(fn (Settlement $record) => [
                        Forms\Components\TextInput::make('to')->label('Empfänger')->email()->required()
                            ->default(fn () => $record->customer?->contact_email),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Sendet die Abrechnung als PDF an den angegebenen Empfänger.')
                    ->action(function (Settlement $record, array $data) {
                        try {
                            \App\Models\MailSetting::current()->apply();
                            $pdf = app(PdfRenderer::class)->render($record->pdfData(), 'exports.settlement');
                            $name = 'abrechnung-' . Str::slug($record->title) . '-' . $record->created_at->format('Ymd') . '.pdf';

                            \Illuminate\Support\Facades\Mail::to($data['to'])
                                ->send(new \App\Mail\SettlementMail($record, $pdf, $name));

                            $record->update(['sent_at' => now(), 'sent_to' => $data['to']]);
                            Notification::make()->title('Abrechnung gesendet an ' . $data['to'])->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Versand fehlgeschlagen')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                Tables\Actions\Action::make('markPaid')
                    ->label('Als ausgezahlt markieren')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Settlement $record) => static::canManage() && ! $record->isPaid())
                    ->form([
                        Forms\Components\DatePicker::make('paid_at')->label('Ausgezahlt am')->default(now())->required(),
                        Forms\Components\TextInput::make('paid_reference')->label('Referenz/Beleg')->helperText('z. B. Überweisungsverwendungszweck'),
                        Forms\Components\Textarea::make('note')->label('Notiz'),
                    ])
                    ->action(function (Settlement $record, array $data) {
                        $record->update([
                            'status' => Settlement::STATUS_PAID,
                            'paid_at' => $data['paid_at'],
                            'paid_reference' => $data['paid_reference'] ?? null,
                            'note' => $data['note'] ?? $record->note,
                        ]);
                        Notification::make()->title('Als ausgezahlt markiert')->success()->send();
                    }),
                Tables\Actions\Action::make('reopen')
                    ->label('Wieder öffnen')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (Settlement $record) => static::canManage() && $record->isPaid())
                    ->requiresConfirmation()
                    ->action(fn (Settlement $record) => $record->update(['status' => Settlement::STATUS_OPEN, 'paid_at' => null])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSettlements::route('/'),
        ];
    }
}
