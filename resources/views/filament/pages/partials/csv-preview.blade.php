<div class="rpt-wrap">
    <table class="rpt">
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($headers as $header)
                        <td>{{ $row[$header] ?? '' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Erste {{ count($rows) }} Zeilen der Datei (Rohdaten, ungemappt).</p>
