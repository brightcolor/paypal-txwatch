<x-filament-panels::page>
    @php($c = $this->connection)

    @if (session('gocardless'))
        <div class="rounded-lg bg-primary-50 dark:bg-primary-950/40 text-primary-700 dark:text-primary-300 px-4 py-3 text-sm">
            {{ session('gocardless') }}
        </div>
    @endif

    <x-filament::section heading="Status">
        <div class="rpt-wrap">
            <table class="rpt" style="min-width: 28rem;">
                <tbody>
                    <tr><td class="lbl">Status</td><td>
                        @switch($c->status)
                            @case('connected') <span class="net">verbunden</span> @break
                            @case('linking') Freigabe läuft… @break
                            @case('expired') <span class="neg">abgelaufen – neu freigeben</span> @break
                            @case('error') <span class="neg">Fehler</span> @break
                            @default nicht verbunden
                        @endswitch
                    </td></tr>
                    <tr><td class="lbl">Bank</td><td>{{ $c->institution_name ?: '–' }}</td></tr>
                    <tr><td class="lbl">Verbundene Konten</td><td>{{ is_array($c->account_ids) ? count($c->account_ids) : 0 }}</td></tr>
                    <tr><td class="lbl">Freigabe gültig bis</td><td>
                        {{ $c->consent_expires_at ? $c->consent_expires_at->format('d.m.Y') : '–' }}
                        @if ($c->consentDaysLeft() !== null)
                            <span @class(['neg' => $c->consentDaysLeft() <= 7, 'muted' => $c->consentDaysLeft() > 7])>
                                (noch {{ $c->consentDaysLeft() }} Tage)
                            </span>
                        @endif
                    </td></tr>
                    <tr><td class="lbl">Letzter Abruf</td><td>{{ $c->last_synced_at ? $c->last_synced_at->format('d.m.Y H:i') : '–' }}</td></tr>
                    @if ($c->last_error)
                        <tr><td class="lbl">Letzter Fehler</td><td class="neg">{{ $c->last_error }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-400">
            Ablauf: Zugangsdaten speichern → Bank wählen → „Bank verbinden" (einmalige Freigabe per TAN, alle 90 Tage) →
            danach täglich automatischer Abruf. Die Umsätze landen unter <strong>Bank → Kontoumsätze</strong> und werden
            automatisch abgeglichen.
        </p>
    </x-filament::section>

    <x-filament::section heading="Einrichtung">
        <form wire:submit="save">
            {{ $this->form }}
            <div class="mt-4">
                <x-filament::button type="submit">Speichern</x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
