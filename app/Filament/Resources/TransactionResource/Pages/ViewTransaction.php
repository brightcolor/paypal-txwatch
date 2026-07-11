<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Filament\Resources\TransactionResource;
use App\Models\Transaction;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewTransaction extends ViewRecord
{
    protected static string $resource = TransactionResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Als nicht relevant markiert')
                ->columns(3)
                ->visible(fn (Transaction $record) => $record->isIrrelevant())
                ->schema([
                    TextEntry::make('irrelevant_reason')->label('Grund')->columnSpanFull(),
                    TextEntry::make('irrelevantMarkedBy.name')->label('Markiert von')->default('–'),
                    TextEntry::make('marked_irrelevant_at')->label('Markiert am')->dateTime('d.m.Y H:i:s'),
                ]),

            Section::make('Transaktion')
                ->columns(3)
                ->schema([
                    TextEntry::make('transaction_id')->label('Transaktions-ID')->copyable(),
                    TextEntry::make('paypal_reference_id')->label('Reference ID'),
                    TextEntry::make('paypal_reference_id_type')->label('Reference ID Type'),
                    TextEntry::make('transaction_event_code')->label('T-Code'),
                    TextEntry::make('transaction_status')->label('Status')->badge(),
                    TextEntry::make('transaction_initiation_date')->label('Initiiert')->dateTime('d.m.Y H:i:s'),
                    TextEntry::make('transaction_updated_date')->label('Aktualisiert')->dateTime('d.m.Y H:i:s'),
                    TextEntry::make('invoice_id')->label('Invoice ID'),
                    TextEntry::make('event_ref')
                        ->label('Event')
                        ->state(fn (Transaction $record) => \App\Services\CustomFieldParser::eventReference($record->custom_field) ?? '–'),
                    TextEntry::make('order_number')
                        ->label('Bestellnummer')
                        ->state(fn (Transaction $record) => \App\Services\CustomFieldParser::orderNumber($record->custom_field) ?? '–')
                        ->url(fn (Transaction $record) => $record->pretixOrderUrl(), shouldOpenInNewTab: true)
                        ->color(fn (Transaction $record) => $record->pretixOrderUrl() ? 'primary' : null)
                        // Same copyable-vs-link conflict as in the table: the copy click
                        // handler would swallow the navigation, so no ->copyable() here.
                        ->icon(fn (Transaction $record) => $record->pretixOrderUrl() ? 'heroicon-m-arrow-top-right-on-square' : null)
                        ->iconPosition(\Filament\Support\Enums\IconPosition::After)
                        ->helperText(fn (Transaction $record) => $record->pretixOrderUrl() ? 'Öffnet die Bestellung in pretix (neues Fenster)' : null),
                    TextEntry::make('reconciliation_status')
                        ->label('pretix-Abgleich')
                        ->badge()
                        ->state(fn (Transaction $record) => match ($record->reconciliation_status) {
                            Transaction::RECONCILIATION_MATCHED => 'abgeglichen',
                            Transaction::RECONCILIATION_MISMATCH => 'Betrag weicht ab',
                            Transaction::RECONCILIATION_UNMATCHED => 'nicht in pretix',
                            default => '–',
                        })
                        ->color(fn (Transaction $record) => match ($record->reconciliation_status) {
                            Transaction::RECONCILIATION_MATCHED => 'success',
                            Transaction::RECONCILIATION_MISMATCH => 'danger',
                            Transaction::RECONCILIATION_UNMATCHED => 'warning',
                            default => 'gray',
                        }),
                    TextEntry::make('custom_field')->label('Verwendungszweck (roh)')->default('–'),
                ]),

            Section::make('Beträge')
                ->columns(4)
                ->schema([
                    TextEntry::make('gross_amount')->label('Brutto')->money(fn ($record) => $record->currency ?? 'EUR'),
                    TextEntry::make('fee_amount')->label('Gebühr')->money(fn ($record) => $record->currency ?? 'EUR'),
                    TextEntry::make('net_amount')->label('Netto')->money(fn ($record) => $record->currency ?? 'EUR'),
                    TextEntry::make('currency')->label('Währung'),
                ]),

            Section::make('Zahler')
                ->columns(3)
                ->schema([
                    TextEntry::make('payer_name')->label('Name'),
                    TextEntry::make('payer_email')->label('E-Mail'),
                    TextEntry::make('payer_country_code')->label('Land'),
                    TextEntry::make('payment_method_type')->label('Zahlungsart'),
                    TextEntry::make('instrument_type')->label('Instrument'),
                    TextEntry::make('protection_eligibility')->label('Protection Eligibility'),
                ]),

            Section::make('Event-Zuordnung')
                ->columns(3)
                ->schema([
                    TextEntry::make('event.name')->label('Event')->default('– nicht zugeordnet –'),
                    TextEntry::make('assignment_method')->label('Zugeordnet über')->default('–'),
                    TextEntry::make('assigned_at')->label('Zugeordnet am')->dateTime('d.m.Y H:i'),
                ]),

            Section::make('Notizen')
                ->schema([
                    TextEntry::make('subject')->label('Betreff'),
                    TextEntry::make('note')->label('Notiz'),
                ]),

            Section::make('Raw JSON (PayPal API)')
                ->collapsed()
                ->schema([
                    TextEntry::make('raw_payload')
                        ->label('')
                        // ->state() (not ->formatStateUsing()) is required here: formatStateUsing
                        // only transforms the value for *display*, but Filament's text-entry blade
                        // reads the entry's raw getState() first to decide whether to render it as
                        // a list - which for an array-cast column like raw_payload returns the raw
                        // array (with PayPal's nested cart/item structures), crashing with "Array to
                        // string conversion" when it tries to implode() nested arrays. ->state()
                        // replaces getState() itself, so the raw array is never seen by that check.
                        ->state(fn ($record) => new \Illuminate\Support\HtmlString(
                            '<pre style="white-space: pre-wrap; font-size: 12px;">'
                            . e(json_encode($record->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                            . '</pre>'
                        )),
                ]),
        ]);
    }
}
