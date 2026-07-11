{{-- Shared report table. Styling lives in the adminlte-theme (.rpt) as real CSS
     because this app has no Tailwind build step - so no arbitrary utility
     classes here. min-width (inline) lets the wrapper scroll horizontally on
     mobile instead of squeezing money values onto two lines. --}}
<div class="rpt-wrap">
    <table class="rpt" style="min-width: 42rem;">
        <thead>
            <tr>
                <th>{{ $labelHeading }}</th>
                <th class="num">Anzahl</th>
                <th class="num">Umsatz</th>
                <th class="num">Gebühr</th>
                <th class="num">Nach Gebühren</th>
                <th class="num">Gebührenquote</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="lbl">{{ $row['label'] }}</td>
                    <td class="num">{{ $row['count'] }}</td>
                    <td class="num">{{ number_format($row['gross'], 2, ',', '.') }}&nbsp;€</td>
                    <td class="num @if($row['fee'] < 0) neg @endif">{{ number_format($row['fee'], 2, ',', '.') }}&nbsp;€</td>
                    <td class="num strong">{{ number_format($row['net'], 2, ',', '.') }}&nbsp;€</td>
                    <td class="num muted">{{ $row['fee_ratio'] }}&nbsp;%</td>
                </tr>
            @empty
                <tr><td colspan="6" class="rpt-empty">Keine Daten im gewählten Zeitraum.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
