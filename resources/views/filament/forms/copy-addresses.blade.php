{{--
    Die Adressliste: hineinklicken kopiert sie.

    Ein Textbereich, den man erst markieren und dann kopieren muss, ist genau ein
    Handgriff zu viel für den einzigen Zweck, den er hat. Der Klick kopiert – und
    sagt es auch, denn eine Zwischenablage ist unsichtbar: ohne Rückmeldung weiss
    niemand, ob der Klick etwas getan hat, und man klickt noch dreimal.
--}}
@php
    $adressen = preg_split('/[;,\n]\s*/', trim((string) $getState()), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $anzahl = count($adressen);
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            kopiert: false,
            zeit: null,
            async kopieren() {
                const text = this.$refs.feld.value;

                if (! text) {
                    return;
                }

                // Markiert wird immer: schlägt das Kopieren fehl, kann man es von
                // Hand zu Ende bringen, statt vor einem toten Feld zu sitzen.
                this.$refs.feld.select();

                try {
                    await navigator.clipboard.writeText(text);
                } catch (e) {
                    // Ältere Browser und unverschlüsselte Verbindungen kennen die
                    // Zwischenablage-API nicht.
                    document.execCommand('copy');
                }

                this.kopiert = true;
                clearTimeout(this.zeit);
                this.zeit = setTimeout(() => { this.kopiert = false }, 2500);
            },
        }"
        class="relative"
    >
        <textarea
            x-ref="feld"
            readonly
            rows="8"
            @click="kopieren()"
            @keydown.enter.prevent="kopieren()"
            title="Klicken kopiert alle Adressen"
            class="tx-field block w-full cursor-pointer border-none px-3 py-1.5 font-mono text-xs"
        >{{ $getState() }}</textarea>

        {{-- Die Rückmeldung sitzt AUF dem Feld, dort wo der Klick war. Eine Meldung
             am Bildschirmrand würde beim Kopieren übersehen. --}}
        <div
            x-show="kopiert"
            x-transition.opacity
            x-cloak
            class="tx-flash pointer-events-none px-3 py-1 text-sm"
        >
            ✓ {{ $anzahl }} {{ $anzahl === 1 ? 'Adresse' : 'Adressen' }} kopiert
        </div>

        {{-- Innerhalb von x-data, sonst kennt Alpine hier `kopiert` nicht und der
             Hinweis bliebe stehen, während oben „kopiert" steht. --}}
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            <span x-show="! kopiert">
                <strong>In das Feld klicken</strong> kopiert alle {{ $anzahl }}
                {{ $anzahl === 1 ? 'Adresse' : 'Adressen' }} in die Zwischenablage – jede genau einmal.
            </span>
            <span x-show="kopiert" x-cloak class="tx-ok">
                In der Zwischenablage. Jetzt im Mailprogramm einfügen.
            </span>
        </p>
    </div>
</x-dynamic-component>
