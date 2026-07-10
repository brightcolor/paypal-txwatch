<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Filament\Resources\TransactionResource;
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
                    TextEntry::make('custom_field')->label('Custom Field'),
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
