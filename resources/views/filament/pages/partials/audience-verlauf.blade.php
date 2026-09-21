{{--
    Eine Zeitreihe als Liniendiagramm.

    ALS DIAGRAMM, weil hier der Verlauf die Antwort ist: die Tagesreihe hat über
    hundert Werte, und als Tabelle sind das über hundert Zeilen, in denen die Form
    der Kurve verschwindet. Die Zahlen bleiben trotzdem erreichbar - die Klassen
    der Vorlaufzeit und die stärksten Verkaufstage stehen als Tabelle daneben.

    Chart.js kommt aus Filament selbst (Alpine-Komponente „chart" des Widgets-Pakets),
    also ohne zusätzliche Bibliothek. Die Komponente liest ihre Farben aus den vier
    Span-Elementen unten und baut das Diagramm beim Wechsel auf Dunkel neu auf.

    wire:ignore haelt Livewire vom Umbauen der Leinwand ab; der Schluessel enthaelt
    die Daten, sodass ein Filterwechsel das Element ersetzt und das Diagramm neu
    entsteht.
--}}
@php
    $beschriftungen = array_map('strval', array_keys($rows));
    $werte = array_map('intval', array_values($rows));
    $schluessel = $id . '-' . substr(md5(json_encode([$beschriftungen, $werte])), 0, 12);
@endphp

@if ($werte === [])
    <p class="aud-hinweis">Für diese Auswahl liegen keine Werte vor.</p>
@else
    <div
        wire:key="{{ $schluessel }}"
        wire:ignore
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
        x-data="chart({
            cachedData: {
                labels: @js($beschriftungen),
                datasets: [{
                    label: @js($label),
                    data: @js($werte),
                    fill: true,
                    tension: 0.25,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHitRadius: 12,
                }],
            },
            options: {
                maintainAspectRatio: false,
                // Fadenkreuz-Verhalten: der Zeiger muss die duenne Linie nicht treffen.
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: { displayColors: false },
                },
                scales: {
                    x: { title: { display: true, text: @js($axis ?? '') }, ticks: { maxTicksLimit: 12, autoSkip: true, maxRotation: 0 } },
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                },
            },
            type: 'line',
        })"
    >
        <canvas x-ref="canvas" style="max-height: 15rem" aria-label="{{ $label }}" role="img"></canvas>

        <span x-ref="backgroundColorElement" class="aud-flaeche"></span>
        <span x-ref="borderColorElement" class="aud-linie"></span>
        <span x-ref="gridColorElement" class="text-gray-200 dark:text-gray-800"></span>
        <span x-ref="textColorElement" class="text-gray-500 dark:text-gray-400"></span>
    </div>
@endif
