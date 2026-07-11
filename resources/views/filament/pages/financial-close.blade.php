<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @if ($this->isOperator())
    @php($r = $this->reconciliation)

    <x-filament::section heading="Auszahlungs-Abgleich (PayPal → Bank)">
        <x-slot name="description">
            Brücke zwischen Einnahmen und Bankkonto: Was kam rein (nach Gebühren), was wurde erstattet und ausgezahlt – und was sollte damit noch im PayPal-Guthaben liegen.
        </x-slot>

        <div class="rpt-wrap">
            <table class="rpt" style="min-width: 30rem;">
                <tbody>
                    <tr><td class="lbl">Eingegangen (brutto)</td><td class="num amt">{{ number_format($r['incoming_gross'], 2, ',', '.') }}&nbsp;€</td></tr>
                    <tr><td class="lbl">./. Gebühren</td><td class="num @if($r['fees'] < 0) neg @endif">{{ number_format($r['fees'], 2, ',', '.') }}&nbsp;€</td></tr>
                    <tr><td class="lbl">= Eingegangen (netto, nach Gebühren &amp; Erstattungen)</td><td class="num net">{{ number_format($r['incoming_net'], 2, ',', '.') }}&nbsp;€</td></tr>
                    <tr><td class="lbl">davon Erstattungen</td><td class="num @if($r['refunds'] < 0) neg @endif">{{ number_format($r['refunds'], 2, ',', '.') }}&nbsp;€</td></tr>
                    <tr><td class="lbl">Ausgezahlt an Bank ({{ $r['payout_count'] }} Auszahlungen)</td><td class="num @if($r['payouts'] < 0) neg @endif">{{ number_format($r['payouts'], 2, ',', '.') }}&nbsp;€</td></tr>
                    <tr style="border-top:2px solid #dee2e6;"><td class="lbl strong">= rechnerischer PayPal-Saldo (Verbleib)</td><td class="num net">{{ number_format($r['expected_balance'], 2, ',', '.') }}&nbsp;€</td></tr>
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-400">Hinweis: Rechnerischer Wert für den gewählten Zeitraum (ohne Anfangsbestand). Reserven/Holds (T21xx) sind keine Bank-Auszahlungen und hier nicht enthalten.</p>
    </x-filament::section>

    <x-filament::section heading="Auszahlungen im Zeitraum" collapsible collapsed>
        <div class="rpt-wrap">
            <table class="rpt" style="min-width: 34rem;">
                <thead>
                    <tr><th>Datum</th><th>T-Code</th><th class="num">Betrag</th><th>Transaktions-ID</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->payoutList as $p)
                        <tr>
                            <td class="lbl">{{ optional($p->transaction_initiation_date)->format('d.m.Y H:i') ?? '–' }}</td>
                            <td>{{ $p->transaction_event_code }}</td>
                            <td class="num @if((float) $p->gross_amount < 0) neg @endif">{{ number_format((float) $p->gross_amount, 2, ',', '.') }}&nbsp;{{ $p->currency }}</td>
                            <td>{{ $p->transaction_id }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="rpt-empty">Keine Auszahlungen im gewählten Zeitraum.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    @endif

    <x-filament::section heading="Monatsabschluss (Steuerberater)">
        <x-slot name="description">Pro Monat: Umsatz, Gebühren, Erstattungen und Umsatzsteuer (echte pretix-Steuer, wo verknüpft, sonst Fallback-Satz). Oben rechts als CSV herunterladbar.</x-slot>

        <div class="rpt-wrap">
            <table class="rpt" style="min-width: 44rem;">
                <thead>
                    <tr>
                        <th>Monat</th>
                        <th class="num">Anzahl</th>
                        <th class="num">Umsatz</th>
                        <th class="num">Gebühren</th>
                        <th class="num">Erstattungen</th>
                        <th class="num">MwSt</th>
                        <th class="num">Netto (ohne MwSt)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->monthly as $m)
                        <tr>
                            <td class="lbl">{{ $m['month'] }}</td>
                            <td class="num">{{ $m['count'] }}</td>
                            <td class="num amt">{{ number_format($m['gross'], 2, ',', '.') }}&nbsp;€</td>
                            <td class="num @if($m['fee'] < 0) neg @endif">{{ number_format($m['fee'], 2, ',', '.') }}&nbsp;€</td>
                            <td class="num @if($m['refunds'] < 0) neg @endif">{{ number_format($m['refunds'], 2, ',', '.') }}&nbsp;€</td>
                            <td class="num">{{ number_format($m['vat'], 2, ',', '.') }}&nbsp;€</td>
                            <td class="num net">{{ number_format($m['net_excl_vat'], 2, ',', '.') }}&nbsp;€</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="rpt-empty">Keine Daten im gewählten Zeitraum.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
