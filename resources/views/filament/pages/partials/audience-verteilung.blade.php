{{--
    Eine Verteilung als Balkenliste: Beschriftung, Anzahl, Anteil.

    Als Tabelle mit CSS-Balken statt als Diagramm, weil die Zahl daneben die
    eigentliche Antwort ist und ein Balken ohne sie nur eine Richtung zeigt.
--}}
@php
    $summe = array_sum($rows) ?: 1;
@endphp

<div class="rpt-wrap">
    <table class="rpt">
        <thead>
            <tr>
                <th>{{ $labelHeading ?? 'Klasse' }}</th>
                <th class="num">Anzahl</th>
                <th class="num">Anteil</th>
                <th class="aud-balkenspalte"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $label => $anzahl)
                <tr>
                    <td class="lbl">{{ $label }}</td>
                    <td class="num">{{ number_format($anzahl, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($anzahl * 100 / $summe, 1, ',', '.') }}&nbsp;%</td>
                    <td class="aud-balkenspalte">
                        <span class="aud-balken" style="width: {{ round($anzahl * 100 / $summe) }}%"></span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="rpt-empty">Für diese Auswahl liegen keine Werte vor.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
