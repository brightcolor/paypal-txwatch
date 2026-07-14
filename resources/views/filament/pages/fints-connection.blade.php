<x-filament-panels::page>
    @php($c = $this->connection)

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
        <p class="mt-3 text-xs text-gray-400">
            Ablauf: Zugangsdaten speichern → „TAN-Verfahren anzeigen" → Verfahren-ID eintragen & speichern →
            „Login / Bank verbinden" → einmal per TAN bestätigen → danach täglich automatischer Abruf. Die Umsätze
            landen unter <strong>Bank → Kontoumsätze</strong> und werden automatisch abgeglichen. Direkt zur Sparkasse,
            ohne Drittanbieter.
        </p>
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
