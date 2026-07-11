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
        return auth()->user()?->can('manage-exports') ?? false;
    }

    public static function canCreate(): bool
    {
        // Settlements are created from an event / customer, never blank.
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
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
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Summe')->money('EUR')),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $s) => $s === Settlement::STATUS_PAID ? 'ausgezahlt' : 'offen')
                    ->color(fn (string $s) => $s === Settlement::STATUS_PAID ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('paid_at')->label('Ausgezahlt am')->dateTime('d.m.Y')->placeholder('–'),
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
                Tables\Actions\Action::make('markPaid')
                    ->label('Als ausgezahlt markieren')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Settlement $record) => ! $record->isPaid())
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
                    ->visible(fn (Settlement $record) => $record->isPaid())
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
