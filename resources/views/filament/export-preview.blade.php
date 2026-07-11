@php($labels = $data['column_labels'] ?? [])
@php($columns = $data['columns'] ?? [])
@php($rowCount = collect($data['groups'] ?? [])->sum(fn ($g) => count($g['rows'])))

<div class="text-sm">
    <p class="mb-2 text-gray-500">
        Vorschau der ersten {{ $limit }} Zeilen (der vollständige Export kann mehr enthalten).
    </p>

    <div class="rpt-wrap">
        <table class="rpt" style="min-width: 40rem;">
            <thead>
                <tr>
                    @foreach ($labels as $label)
                        <th @class(['num' => in_array($label, ['Betrag', 'Gebühr', 'Nach Gebühren', 'MwSt', 'Netto', 'Brutto'])])>{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($data['groups'] as $group)
                    @foreach ($group['rows'] as $row)
                        <tr>
                            @foreach ($columns as $col)
                                <td>{{ $row[$col] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="{{ max(count($labels), 1) }}" class="rpt-empty">Keine Daten für den aktuellen Filter.</td></tr>
                @endforelse
            </tbody>
            @if (! empty($data['grand_total']))
                <tfoot>
                    <tr style="border-top:2px solid #dee2e6;">
                        <td class="strong">Gesamt ({{ $data['grand_total']['count'] }} in Vorschau)</td>
                        <td colspan="{{ max(count($labels) - 1, 1) }}" class="num strong">
                            Umsatz: <span class="amt">{{ number_format($data['grand_total']['gross'], 2, ',', '.') }} €</span>
                            &nbsp;·&nbsp; MwSt: {{ number_format($data['grand_total']['vat'], 2, ',', '.') }} €
                            &nbsp;·&nbsp; Netto: <span class="net">{{ number_format($data['grand_total']['net_excl_vat'], 2, ',', '.') }} €</span>
                        </td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
