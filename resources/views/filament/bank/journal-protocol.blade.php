{{--
    Das Protokoll eines Umsatzes: Abruf, Erkennung, spätere Änderungen.

    Älteste Zeile zuerst – es wird als Verlauf gelesen, nicht als Statusanzeige.
    Der durchsuchte Text steht mit dabei, weil er die Frage „warum wurde nichts
    gefunden" als einziger beantwortet.
--}}
<div class="rpt-wrap">
    <table class="rpt" style="min-width: 34rem;">
        <tbody>
            <tr>
                <td class="lbl">Betrag</td>
                <td>
                    <span class="{{ (float) $entry->amount < 0 ? 'neg' : 'net' }}">
                        {{ number_format((float) $entry->amount, 2, ',', '.') }} {{ $entry->currency }}
                    </span>
                    @if ((float) $entry->amount < 0)
                        <span class="text-xs text-gray-400">· Erstattung</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td class="lbl">Gebucht</td>
                <td>{{ $entry->booked_on?->format('d.m.Y') ?? '–' }}</td>
            </tr>
            <tr>
                <td class="lbl">Verwendungszweck</td>
                <td style="white-space: normal;">{{ $entry->purpose ?: '–' }}</td>
            </tr>
            <tr>
                <td class="lbl">Zustand</td>
                {{-- Was aus der Zuordnung folgt. Steht ueber dem Verlauf, weil es die
                     Frage beantwortet, mit der dieses Fenster geoeffnet wurde: muss
                     ich hier etwas tun? --}}
                <td>
                    <strong>{{ $entry->stateLabel() }}</strong>
                    @if ($entry->possible_double_payment)
                        <span class="neg text-xs">· zweiter Geldeingang auf dieselbe Bestellung</span>
                    @elseif ($entry->pretix_order_status === 'n')
                        <span class="text-xs text-gray-400">· Zahlungsmeldung an pretix fällig</span>
                    @elseif ($entry->isSettled())
                        <span class="text-xs text-gray-400">· nichts zu tun</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td class="lbl">durchsucht als</td>
                {{-- Das ist der Text, in dem gesucht wurde – Trenn- und
                     Leerzeichen entfernt. Ohne ihn bleibt jede Frage nach einem
                     ausgebliebenen Treffer unbeantwortbar. --}}
                <td style="white-space: normal;"><code class="text-xs">{{ $entry->match_haystack ?: '–' }}</code></td>
            </tr>
        </tbody>
    </table>
</div>

@if ($entry->hasSuggestion())
    <div class="mt-4">
        <p class="text-sm">
            <strong>Vorschläge</strong> – nicht zugeordnet. Ein Zeichen weicht ab; die Entscheidung
            liegt bei dir.
        </p>
        <div class="rpt-wrap mt-2">
            <table class="rpt" style="min-width: 34rem;">
                <thead>
                    <tr>
                        <th>Bestellung</th>
                        <th>im Zweck stand</th>
                        <th>Zustand</th>
                        <th>Betrag der Bestellung</th>
                        <th>Betrag passt</th>
                        <th class="num">Sicherheit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entry->match_candidates as $c)
                        <tr>
                            <td><strong>{{ $c['code'] }}</strong></td>
                            <td><code class="text-xs">{{ $c['found'] }}</code></td>
                            {{-- Ein Vorschlag zu einer bezahlten Bestellung ist kaum je
                                 gemeint; ohne diese Spalte sahen beide gleich aus. --}}
                            <td class="{{ ($c['order_open'] ?? false) ? 'net' : '' }}">
                                {{ ($c['order_open'] ?? false) ? 'offen' : ((($c['order_status'] ?? null) === 'p') ? 'bezahlt' : '–') }}
                            </td>
                            <td>{{ $c['order_total'] !== null ? number_format((float) $c['order_total'], 2, ',', '.') . ' EUR' : '–' }}</td>
                            <td class="{{ ($c['amount_matches'] ?? false) ? 'net' : 'neg' }}">
                                {{ ($c['amount_matches'] ?? false) ? 'ja' : 'nein' }}
                            </td>
                            <td class="num">{{ $c['score'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-400">
            Ein passender Betrag ist die stärkste Bestätigung: Bei den gemessenen Tippfehlern engte er
            über tausend Bestellungen auf genau eine ein. Passt er nicht, ist der Vorschlag
            wahrscheinlich falsch.
        </p>
    </div>
@endif

<div class="mt-4">
    <p class="text-sm"><strong>Verlauf</strong></p>
    <div class="rpt-wrap mt-2">
        <table class="rpt" style="min-width: 34rem;">
            <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td class="lbl" style="white-space: nowrap;">
                            {{ $event->at?->format('d.m.Y H:i') }}
                        </td>
                        <td style="white-space: nowrap;">
                            <span class="{{ in_array($event->kind, ['matched', 'promoted'], true) ? 'net' : (in_array($event->kind, ['suggested', 'changed'], true) ? 'neg' : '') }}">
                                {{ $event->label() }}
                            </span>
                        </td>
                        <td style="white-space: normal;">{{ $event->message }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="muted">
                            Noch kein Protokoll – dieser Eintrag stammt aus einem Abruf vor Einführung
                            der Aufzeichnung.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
