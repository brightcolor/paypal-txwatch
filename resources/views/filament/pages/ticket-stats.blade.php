<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <x-filament::section heading="Kapazität &amp; Verkauf je Event">
        <x-slot name="description">Live aus den pretix-Kontingenten (zwischengespeichert, oben rechts aktualisierbar). „Verkauft/blockiert" = Kapazität − verfügbar.</x-slot>

        <div class="rpt-wrap">
            <table class="rpt" style="min-width: 40rem;">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th class="num">Kapazität</th>
                        <th class="num">Verkauft/blockiert</th>
                        <th class="num">Verfügbar</th>
                        <th class="num">Auslastung</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $row)
                        <tr>
                            <td class="lbl">{{ $row['name'] }}</td>
                            <td class="num">{{ $row['unlimited'] ? 'unbegrenzt' : ($row['capacity'] !== null ? number_format($row['capacity'], 0, ',', '.') : '–') }}</td>
                            <td class="num amt">{{ number_format($row['sold'], 0, ',', '.') }}</td>
                            <td class="num net">{{ number_format($row['available'], 0, ',', '.') }}</td>
                            <td class="num">
                                @if ($row['ratio'] !== null)
                                    <span @class(['neg' => $row['ratio'] >= 90])>{{ number_format($row['ratio'], 1, ',', '.') }}&nbsp;%</span>
                                @else
                                    –
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="rpt-empty">Keine Events/Kontingente gefunden (oder Verbindung nicht erreichbar).</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
