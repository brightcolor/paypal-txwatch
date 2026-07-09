<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
    <table class="w-full text-xs">
        <thead class="bg-gray-50 dark:bg-gray-800">
            <tr>
                @foreach ($headers as $header)
                    <th class="px-2 py-1.5 text-left font-medium text-gray-600 dark:text-gray-300">{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    @foreach ($headers as $header)
                        <td class="px-2 py-1.5 text-gray-700 dark:text-gray-300">{{ $row[$header] ?? '' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="px-2 py-1.5 text-xs text-gray-400">Erste {{ count($rows) }} Zeilen der Datei (Rohdaten, ungemappt).</p>
</div>
