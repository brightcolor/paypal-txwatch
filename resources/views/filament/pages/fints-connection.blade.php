<x-filament-panels::page>
    @php($c = $this->connection)
    @php($stillgelegt = $this->disabledReason)

    {{--
        STILLGELEGT: die Begründung steht ganz oben, nicht als Fussnote.

        Die Seite bleibt erreichbar und zeigt weiter Status und gespeicherte
        Angaben. Sie ganz zu verstecken wäre bequemer und schlechter: Wer den
        Weg von früher kennt, sucht ihn dann und findet nichts, was erklärt,
        wohin er verschwunden ist – und die hinterlegten Zugangsdaten wirkten
        gelöscht.
    --}}
    @if ($stillgelegt)
        <x-filament::section heading="Stillgelegt">
            <p class="text-sm">{{ $stillgelegt }}</p>
            <p class="mt-3 text-sm">
                <strong>Ihre gespeicherten Zugangsdaten bleiben unangetastet.</strong> Sie stehen unten
                und gelten wieder, sobald der Weg offen ist – es geht nichts verloren.
            </p>
            <p class="mt-3 text-xs text-gray-400">
                Öffnen kann das nur der Betreiber, über die Umgebungsvariable
                <code>FINTS_ENABLED=1</code>. Sinnvoll erst, wenn die Registrierungsnummer bei der
                Deutschen Kreditwirtschaft freigeschaltet ist – vorher endet jeder Abruf in
                Rückmeldung 9078.
            </p>
        </x-filament::section>
    @endif

    <x-filament::section heading="Status">
        <div class="rpt-wrap">
            <table class="rpt" style="min-width: 28rem;">
                <tbody>
                    <tr><td class="lbl">Status</td><td>
                        @switch($c->status)
                            @case('active') <span class="net">aktiv</span> @break
                            @case('needs_tan') <span class="neg">TAN erforderlich</span> @break
                            @case('needs_reauth') <span class="neg">neu anmelden nötig</span> @break
                            @case('error') <span class="neg">Fehler</span> @break
                            @default nicht verbunden
                        @endswitch
                    </td></tr>
                    <tr><td class="lbl">Bank (BLZ)</td><td>{{ $c->bank_code ?: '–' }}</td></tr>
                    <tr><td class="lbl">Konto (IBAN)</td><td>{{ $c->iban ?: '–' }}</td></tr>
                    <tr><td class="lbl">Letzter Abruf</td><td>{{ $c->last_synced_at ? $c->last_synced_at->format('d.m.Y H:i') : '–' }}</td></tr>
                    @if ($c->last_error)
                        <tr><td class="lbl">Letzter Fehler</td><td class="neg">{{ $c->last_error }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
        {{-- Der Ablauf hilft nur, solange er begehbar ist. --}}
        @unless ($stillgelegt)
            <p class="mt-3 text-xs text-gray-400">
                Ablauf: Zugangsdaten speichern → „TAN-Verfahren anzeigen" → Verfahren-ID eintragen & speichern →
                „Login / Bank verbinden" → einmal per TAN bestätigen → danach täglich automatischer Abruf. Die Umsätze
                landen unter <strong>Bank → Kontoumsätze</strong> und werden automatisch abgeglichen. Direkt zur Sparkasse,
                ohne Drittanbieter.
            </p>
        @endunless

        @unless ($c->hasCredentials() || $stillgelegt)
            <p class="mt-2 text-xs" style="color: var(--ak-warning, #ffc107);">
                <strong>Nächster Schritt:</strong> Zugangsdaten unten ausfüllen und <strong>speichern</strong>.
                Die Schaltflächen <em>„TAN-Verfahren anzeigen"</em> und <em>„Login / Bank verbinden"</em> erscheinen
                danach oben rechts im Seitenkopf – sie brauchen BLZ, FinTS-URL, Registrierungsnummer, Anmeldename und PIN.
            </p>
        @endunless
    </x-filament::section>

    @if ($c->status === 'needs_tan')
        <x-filament::section heading="TAN-Freigabe">
            <p class="text-sm">{{ $c->tan_challenge ?: 'Die Bank verlangt eine TAN. Bitte oben „TAN eingeben".' }}</p>
            @if ($c->tan_image)
                <img src="{{ $c->tan_image }}" alt="TAN-Challenge" class="mt-3 max-w-xs rounded border border-gray-200 dark:border-white/10" />
            @endif
        </x-filament::section>
    @endif

    <x-filament::section heading="Einrichtung">
        <form wire:submit="save">
            {{ $this->form }}
            <div class="mt-4">
                <x-filament::button type="submit">Speichern</x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
