<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500">
                <th class="py-1.5 pr-4">{{ $labelHeading }}</th>
                <th class="py-1.5 pr-4 text-right">Anzahl</th>
                <th class="py-1.5 pr-4 text-right">Brutto</th>
                <th class="py-1.5 pr-4 text-right">Gebühr</th>
                <th class="py-1.5 pr-4 text-right">Netto</th>
                <th class="py-1.5 pr-4 text-right">Gebührenquote</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="py-1.5 pr-4 font-medium">{{ $row['label'] }}</td>
                    <td class="py-1.5 pr-4 text-right">{{ $row['count'] }}</td>
                    <td class="py-1.5 pr-4 text-right">{{ number_format($row['gross'], 2, ',', '.') }} €</td>
                    <td class="py-1.5 pr-4 text-right">{{ number_format($row['fee'], 2, ',', '.') }} €</td>
                    <td class="py-1.5 pr-4 text-right">{{ number_format($row['net'], 2, ',', '.') }} €</td>
                    <td class="py-1.5 pr-4 text-right">{{ $row['fee_ratio'] }}%</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-3 text-gray-400">Keine Daten im gewählten Zeitraum.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
