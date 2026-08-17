<x-filament-panels::page>
    @php($c = $this->connection)
    @php($vault = $this->vaultStatus)
    @php($setupReason = $this->setupReason)

    {{-- Der Rückläufer der Bank landet mit einer Meldung in der Sitzung. --}}
    @if (session('bank_error'))
        <x-filament::section heading="Die Freigabe kam nicht zustande">
            <p class="text-sm">{{ session('bank_error') }}</p>
        </x-filament::section>
    @elseif (session('status') === 'verbunden')
        <x-filament::section heading="Bank verbunden">
            <p class="text-sm">
                Die Freigabe steht. Mit <strong>„Jetzt abrufen"</strong> oben holst du die Umsätze; danach
                läuft der Abruf täglich von selbst. Die Umsätze landen unter
                <strong>Bank → Kontoumsätze</strong> und werden automatisch abgeglichen.
            </p>
        </x-filament::section>
    @endif

    {{--
        DIE UMGEBUNG SCHLÄGT DIE OBERFLÄCHE, und das gehört nach oben statt in
        eine Fussnote: Sonst lädt jemand hoch, sieht „hinterlegt" und sucht
        danach, warum die Bank weiter den alten Schlüssel sieht.
    --}}
    @if ($this->configuredByEnv)
        <x-filament::section heading="Über Umgebungsvariablen eingerichtet">
            <p class="text-sm">
                Diese Installation liest Kennung und/oder Schlüssel aus
                <code>ENABLEBANKING_APPLICATION_ID</code> / <code>ENABLEBANKING_KEY_PATH</code>.
                <strong>Die gelten vor allem, was hier hochgeladen wird.</strong>
            </p>
            <p class="mt-2 text-xs text-gray-400">
                Zum Tauschen über die Oberfläche die beiden Variablen aus der Umgebung nehmen und die
                Anwendung neu starten.
            </p>
        </x-filament::section>
    @endif

    <x-filament::section heading="Status">
        <div class="rpt-wrap">
            <table class="rpt" style="min-width: 30rem;">
                <tbody>
                    <tr><td class="lbl">Zugang der Installation</td><td>
                        @if ($setupReason)
                            <span class="neg">nicht eingerichtet</span>
                        @else
                            <span class="net">eingerichtet</span>
                        @endif
                    </td></tr>

                    @if ($setupReason)
                        <tr><td class="lbl">Was fehlt</td><td class="neg">{{ $setupReason }}</td></tr>
                    @else
                        <tr><td class="lbl">Anwendungskennung</td><td>{{ $vault['application_id'] ?: '–' }}</td></tr>
                        <tr><td class="lbl">Umgebung</td><td>
                            @switch($vault['environment'])
                                @case('prod') Produktion @break
                                @case('sandbox') Sandbox @break
                                @default unbekannt
                            @endswitch
                            @if ($vault['bits'])
                                <span class="text-xs text-gray-400">· RSA {{ $vault['bits'] }} Bit</span>
                            @endif
                        </td></tr>
                        {{-- Zum Vergleichen gedacht, nicht zum Lesen: gekürzt, aber eindeutig. --}}
                        <tr><td class="lbl">Fingerabdruck</td><td>
                            <span class="text-xs">{{ \Illuminate\Support\Str::limit($vault['fingerprint'] ?? '', 32, '…') }}</span>
                        </td></tr>
                        <tr><td class="lbl">Hinterlegt</td><td>
                            {{ $vault['uploaded_at'] ? \Illuminate\Support\Carbon::parse($vault['uploaded_at'])->format('d.m.Y H:i') : '–' }}
                            @if ($vault['filename'])
                                <span class="text-xs text-gray-400">· {{ $vault['filename'] }}</span>
                            @endif
                        </td></tr>
                    @endif

                    <tr><td class="lbl">Verbindung</td><td>
                        @switch($c->status)
                            @case('active') <span class="net">aktiv</span> @break
                            @case('expired') <span class="neg">Zustimmung abgelaufen</span> @break
                            @case('error') <span class="neg">Fehler</span> @break
                            @default nicht verbunden
                        @endswitch
                    </td></tr>
                    <tr><td class="lbl">Bank</td><td>{{ $c->aspsp_name ?: '–' }}</td></tr>
                    <tr><td class="lbl">Konto (IBAN)</td><td>{{ $c->iban ?: '–' }}</td></tr>

                    @if ($c->access_valid_until)
                        <tr><td class="lbl">Freigabe gilt bis</td><td>
                            {{ $c->access_valid_until->format('d.m.Y') }}
                            @php($left = $c->consentDaysLeft())
                            @if ($c->consentExpired())
                                <span class="neg">– abgelaufen</span>
                            @elseif ($c->consentEndsSoon())
                                {{-- Die Frist gehört DANEBEN, nicht in eine Meldung hinterher: Wer
                                     sie kennt, erneuert rechtzeitig; wer sie erst nach dem Ablauf
                                     erfährt, hat schon Lücken in den Umsätzen. --}}
                                <span class="neg">– nur noch {{ $left }} Tage, bitte rechtzeitig erneuern</span>
                            @else
                                <span class="text-xs text-gray-400">· noch {{ $left }} Tage</span>
                            @endif
                        </td></tr>
                    @endif

                    <tr><td class="lbl">Letzter Abruf</td><td>{{ $c->last_synced_at ? $c->last_synced_at->format('d.m.Y H:i') : '–' }}</td></tr>
                    @if ($c->last_error)
                        <tr><td class="lbl">Letzter Fehler</td><td class="neg">{{ $c->last_error }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-gray-400">
            Ablauf: Schlüssel hochladen → <em>Selbsttest</em> → Bank wählen & speichern →
            <em>„Zur Bank und freigeben"</em> → bei der Bank anmelden und erlauben → danach täglich
            automatischer Abruf. TxWatch bekommt Ihre Zugangsdaten nie zu sehen, nur einen
            Leseschlüssel.
        </p>

        {{--
            Die Adresse steht sichtbar auf der Seite und nicht nur im Selbsttest:
            Sie muss im Control Panel zeichengenau eingetragen sein, und wer sie
            dort nachträgt, will sie kopieren können, ohne vorher einen Test zu
            fahren.
        --}}
        <p class="mt-2 text-xs text-gray-400">
            Im Control Panel von Enable Banking muss diese Rückkehr-Adresse eingetragen sein:<br>
            <code>{{ $this->callbackUrl }}</code>
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
