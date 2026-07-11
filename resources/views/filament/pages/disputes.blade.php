<x-filament-panels::page>
    <x-filament::section heading="Offene Käuferkonflikte">
        <x-slot name="description">Live aus der PayPal-Disputes-API (zwischengespeichert, oben rechts aktualisierbar). Rot markiert: Antwortfrist in den nächsten 3 Tagen oder bereits abgelaufen – hier zuerst handeln, bevor daraus eine Rückbuchung wird.</x-slot>

        <div class="rpt-wrap">
            <table class="rpt" style="min-width: 46rem;">
                <thead>
                    <tr>
                        <th>Erstellt</th>
                        <th>Konto</th>
                        <th>Status</th>
                        <th>Grund</th>
                        <th class="num">Betrag</th>
                        <th>Antwort bis</th>
                        <th>Transaktion</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->disputes as $d)
                        @php($due = $d['response_due'] ? \Illuminate\Support\Carbon::parse($d['response_due']) : null)
                        @php($urgent = $due && $due->lte(now()->addDays(3)))
                        <tr>
                            <td class="lbl">{{ $d['created'] ? \Illuminate\Support\Carbon::parse($d['created'])->format('d.m.Y') : '–' }}</td>
                            <td>{{ $d['account'] }}</td>
                            <td>{{ str_replace('_', ' ', $d['status']) }}</td>
                            <td>{{ $d['reason'] ? str_replace('_', ' ', $d['reason']) : '–' }}</td>
                            <td class="num @if($d['amount'] > 0) amt @endif">{{ number_format($d['amount'], 2, ',', '.') }}&nbsp;{{ $d['currency'] }}</td>
                            <td @class(['neg' => $urgent])>{{ $due ? $due->format('d.m.Y') : '–' }}</td>
                            <td>{{ $d['transaction_id'] ?? '–' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="rpt-empty">Keine offenen Käuferkonflikte. 🎉</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
