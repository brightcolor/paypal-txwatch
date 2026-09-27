<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-filament::section>
            <div class="tx-kpi-label">Event-Zuordnungsquote</div>
            <div class="tx-kpi-value">{{ $this->assignmentRatio['ratio'] }}%</div>
            <div class="tx-kpi-note">{{ $this->assignmentRatio['assigned'] }} von {{ $this->assignmentRatio['total'] }} zugeordnet</div>
        </x-filament::section>

        <x-filament::section>
            <div class="tx-kpi-label">Rückzahlungen/Reversals</div>
            <div class="tx-kpi-value tx-bad">{{ $this->refundsSummary['count'] }}</div>
            <div class="tx-kpi-note">{{ number_format($this->refundsSummary['total'], 2, ',', '.') }} €</div>
        </x-filament::section>

        <x-filament::section>
            <div class="tx-kpi-label">Nicht zugeordnete Transaktionen</div>
            <div class="tx-kpi-value tx-warn">{{ $this->assignmentRatio['unassigned'] }}</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Gebührenanalyse nach Event">
        @include('filament.pages.partials.report-table', ['rows' => $this->feesByEvent, 'labelHeading' => 'Event'])
    </x-filament::section>

    <x-filament::section heading="Gebührenanalyse nach Monat">
        @include('filament.pages.partials.report-table', ['rows' => $this->feesByMonth, 'labelHeading' => 'Monat'])
    </x-filament::section>

    <x-filament::section heading="PayPal-Konten-Vergleich">
        @include('filament.pages.partials.report-table', ['rows' => $this->accountComparison, 'labelHeading' => 'Konto'])
    </x-filament::section>

    <x-filament::section heading="Event-Kürzel-Analyse (aus Bestellnummer)">
        <div class="rpt-wrap">
            <table class="rpt" style="min-width: 22rem;">
                <thead>
                    <tr>
                        <th>Event-Kürzel</th>
                        <th class="num">Anzahl</th>
                        <th class="num">Umsatz</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->customFieldPrefixes as $row)
                        <tr>
                            <td class="lbl">{{ $row['prefix'] }}</td>
                            <td class="num">{{ $row['count'] }}</td>
                            <td class="num amt">{{ number_format($row['gross'], 2, ',', '.') }}&nbsp;€</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="rpt-empty">Keine Daten im gewählten Zeitraum.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
