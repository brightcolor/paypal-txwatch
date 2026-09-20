<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @php
        $stats = $this->stats;
        $overlap = $this->overlap;
        $firstTime = $this->firstTime;
        $auswahl = $this->currentQuery();
        $dim = $this->dimensions;

        $ticketarten = $dim->ticketTypes($auswahl);
        $treue = $dim->sameTypeAcrossEvents($auswahl);
        $vorlauf = $dim->leadTime($auswahl);
        $gruppen = $dim->groupSize($auswahl);
        $verlauf = $dim->salesCurve($auswahl);
        $werte = $dim->orderValue($auswahl);
        $gutscheine = $dim->vouchers($auswahl);
        $zahlarten = $dim->paymentProviders($auswahl);
        $herkunft = $dim->origin($auswahl);
        $storno = $dim->cancellations($auswahl);
        $fragen = $dim->questions($auswahl);
    @endphp

    <div class="aud-kacheln">
        <x-filament::section>
            <div class="aud-klein">Käufer</div>
            <div class="aud-gross">{{ number_format($stats['buyers'], 0, ',', '.') }}</div>
            <div class="aud-fein">{{ number_format($stats['multi_event_buyers'], 0, ',', '.') }} bei mehreren Veranstaltungen</div>
        </x-filament::section>

        <x-filament::section>
            <div class="aud-klein">Tickets</div>
            <div class="aud-gross">{{ number_format($stats['tickets'], 0, ',', '.') }}</div>
            <div class="aud-fein">aus {{ number_format($stats['orders'], 0, ',', '.') }} Bestellungen</div>
        </x-filament::section>

        <x-filament::section>
            <div class="aud-klein">Umsatz</div>
            <div class="aud-gross">{{ number_format($stats['revenue'], 2, ',', '.') }} €</div>
            <div class="aud-fein">Ticketpreise aus pretix</div>
        </x-filament::section>

        <x-filament::section>
            <div class="aud-klein">Anteil wiederkehrend</div>
            <div class="aud-gross">{{ number_format($stats['returning_share'], 1, ',', '.') }}&nbsp;%</div>
            <div class="aud-fein">{{ number_format($stats['tickets_without_buyer'], 0, ',', '.') }} Tickets ohne Käuferadresse</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Überschneidung der Veranstaltungen">
        <p class="aud-hinweis">
            Gelesen von links nach rechts: von den Käufern der Zeile waren so viele auch bei der Veranstaltung der Spalte.
        </p>

        <div class="rpt-wrap">
            <table class="rpt">
                <thead>
                    <tr>
                        <th>Veranstaltung</th>
                        <th class="num">Käufer</th>
                        @foreach ($overlap['events'] as $spalte)
                            <th class="num">{{ $this->eventLabel($spalte) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($overlap['rows'] as $slug => $zeile)
                        <tr>
                            <td class="lbl">{{ $this->eventLabel($slug) }}</td>
                            <td class="num">{{ number_format($zeile['buyers'], 0, ',', '.') }}</td>
                            @foreach ($overlap['events'] as $spalte)
                                <td class="num {{ $slug === $spalte ? 'aud-diagonale' : '' }}">
                                    {{ number_format($zeile['shared'][$spalte]['count'], 0, ',', '.') }}
                                    <span class="aud-fein">{{ number_format($zeile['shared'][$spalte]['share'], 1, ',', '.') }}&nbsp;%</span>
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="2" class="rpt-empty">Für diese Auswahl liegen keine Käufer vor.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Neu und wiederkehrend je Veranstaltung">
        <p class="aud-hinweis">
            Wiederkehrend heißt: die früheste Bestellung dieser Person gehört zu einer anderen Veranstaltung.
        </p>

        <div class="rpt-wrap">
            <table class="rpt">
                <thead>
                    <tr>
                        <th>Veranstaltung</th>
                        <th class="num">Erstkäufer</th>
                        <th class="num">Wiederkehrend</th>
                        <th class="num">Anteil wiederkehrend</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($firstTime as $slug => $zahlen)
                        <tr>
                            <td class="lbl">{{ $this->eventLabel($slug) }}</td>
                            <td class="num">{{ number_format($zahlen['first_time'], 0, ',', '.') }}</td>
                            <td class="num">{{ number_format($zahlen['returning'], 0, ',', '.') }}</td>
                            <td class="num">{{ number_format($zahlen['share'], 1, ',', '.') }}&nbsp;%</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="rpt-empty">Für diese Auswahl liegen keine Käufer vor.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <div class="aud">
        {{ $this->table }}
    </div>

    <x-filament::section heading="Ticketarten">
        <p class="aud-hinweis">
            {{ number_format($treue['loyal'], 0, ',', '.') }} von {{ number_format($treue['buyers'], 0, ',', '.') }}
            Personen mit mehreren Veranstaltungen wählen überall dieselbe Ticketart
            ({{ number_format($treue['share'], 1, ',', '.') }}&nbsp;%). Verglichen wird der Name der Ticketart.
        </p>

        <div class="rpt-wrap">
            <table class="rpt">
                <thead>
                    <tr>
                        <th>Veranstaltung</th>
                        <th>Ticketart</th>
                        <th class="num">Tickets</th>
                        <th class="num">Umsatz</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ticketarten as $art)
                        <tr>
                            <td class="lbl">{{ $this->eventLabel($art['event']) }}</td>
                            <td>{{ $art['name'] }}</td>
                            <td class="num">{{ number_format($art['tickets'], 0, ',', '.') }}</td>
                            <td class="num amt">{{ number_format($art['revenue'], 2, ',', '.') }} €</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="rpt-empty">Für diese Auswahl liegen keine Tickets vor.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Vorlaufzeit">
        <p class="aud-hinweis">
            Tage zwischen Bestellung und Veranstaltungstag.
            {{ number_format($vorlauf['covered'], 0, ',', '.') }} Tickets sind erfasst,
            {{ number_format($vorlauf['uncovered'], 0, ',', '.') }} gehören zu Veranstaltungen ohne gepflegtes Datum.
        </p>

        @include('filament.pages.partials.audience-verteilung', ['rows' => $vorlauf['classes'], 'labelHeading' => 'Vorlauf'])
    </x-filament::section>

    <x-filament::section heading="Gruppengröße">
        <p class="aud-hinweis">
            Tickets je Bestellung, im Mittel {{ number_format($gruppen['average'], 2, ',', '.') }}.
            Bei {{ number_format($gruppen['with_other_attendee'], 0, ',', '.') }} von
            {{ number_format($gruppen['comparable'], 0, ',', '.') }} vergleichbaren Tickets steht ein anderer Name
            auf dem Ticket als in der Rechnungsadresse.
        </p>

        @include('filament.pages.partials.audience-verteilung', ['rows' => $gruppen['classes'], 'labelHeading' => 'Tickets je Bestellung'])
    </x-filament::section>

    <x-filament::section heading="Verkaufsverlauf">
        <div class="aud-spalten">
            <div>
                <h4 class="aud-klein">Nach Wochentag</h4>
                @include('filament.pages.partials.audience-verteilung', ['rows' => $verlauf['by_weekday'], 'labelHeading' => 'Wochentag'])
            </div>
            <div>
                <h4 class="aud-klein">Nach Tageszeit</h4>
                @include('filament.pages.partials.audience-verteilung', ['rows' => $verlauf['by_hour_block'], 'labelHeading' => 'Uhrzeit'])
            </div>
        </div>

        <h4 class="aud-klein aud-abstand">Tage vor der Veranstaltung</h4>
        <p class="aud-hinweis">
            Auf dieser Achse liegen mehrere Veranstaltungen übereinander und lassen sich vergleichen.
            {{ number_format($verlauf['without_event_date'], 0, ',', '.') }} Bestellungen gehören zu
            Veranstaltungen ohne gepflegtes Datum und fehlen hier.
        </p>
        @include('filament.pages.partials.audience-verteilung', ['rows' => $verlauf['by_days_before'], 'labelHeading' => 'Tage vorher'])

        <h4 class="aud-klein aud-abstand">Bestellungen je Tag</h4>
        @include('filament.pages.partials.audience-verteilung', ['rows' => $verlauf['by_day'], 'labelHeading' => 'Tag'])
    </x-filament::section>

    <x-filament::section heading="Bestellwert">
        <p class="aud-hinweis">
            Median {{ number_format($werte['median'], 2, ',', '.') }} €,
            Mittelwert {{ number_format($werte['average'], 2, ',', '.') }} €.
        </p>

        @include('filament.pages.partials.audience-verteilung', ['rows' => $werte['classes'], 'labelHeading' => 'Bestellwert'])
    </x-filament::section>

    <x-filament::section heading="Gutscheine">
        <p class="aud-hinweis">
            {{ number_format($gutscheine['with'], 0, ',', '.') }} von
            {{ number_format($gutscheine['total'], 0, ',', '.') }} Tickets tragen einen Gutschein
            ({{ number_format($gutscheine['share'], 1, ',', '.') }}&nbsp;%). pretix liefert dabei die Kennung
            des Gutscheins.
        </p>

        <div class="rpt-wrap">
            <table class="rpt">
                <thead>
                    <tr><th>Gutschein-Kennung</th><th class="num">Tickets</th></tr>
                </thead>
                <tbody>
                    @forelse ($gutscheine['top'] as $gutschein)
                        <tr>
                            <td class="lbl">{{ $gutschein['voucher'] }}</td>
                            <td class="num">{{ number_format($gutschein['tickets'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="rpt-empty">In dieser Auswahl wurde kein Gutschein eingelöst.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Zahlungsart">
        @include('filament.pages.partials.audience-verteilung', ['rows' => $zahlarten, 'labelHeading' => 'Zahlungsart'])
    </x-filament::section>

    <x-filament::section heading="Herkunft">
        <p class="aud-hinweis">
            Abdeckung: {{ number_format($herkunft['coverage'], 1, ',', '.') }}&nbsp;% der Tickets tragen eine
            Rechnungsadresse ({{ number_format($herkunft['covered'], 0, ',', '.') }} von
            {{ number_format($herkunft['total'], 0, ',', '.') }}). Die Verteilung beschreibt diesen Teil.
        </p>

        <div class="aud-spalten">
            <div>
                <h4 class="aud-klein">Nach PLZ-Region</h4>
                <div class="rpt-wrap">
                    <table class="rpt">
                        <thead><tr><th>Region</th><th class="num">Tickets</th></tr></thead>
                        <tbody>
                            @forelse ($herkunft['regions'] as $region)
                                <tr><td class="lbl">{{ $region['region'] }}</td><td class="num">{{ number_format($region['tickets'], 0, ',', '.') }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="rpt-empty">Keine Adressen in dieser Auswahl.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div>
                <h4 class="aud-klein">Häufigste Orte</h4>
                <div class="rpt-wrap">
                    <table class="rpt">
                        <thead><tr><th>Ort</th><th class="num">Tickets</th></tr></thead>
                        <tbody>
                            @forelse ($herkunft['cities'] as $ort)
                                <tr><td class="lbl">{{ $ort['city'] }}</td><td class="num">{{ number_format($ort['tickets'], 0, ',', '.') }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="rpt-empty">Keine Adressen in dieser Auswahl.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div>
                <h4 class="aud-klein">Nach Land</h4>
                @include('filament.pages.partials.audience-verteilung', ['rows' => $herkunft['countries'], 'labelHeading' => 'Land'])
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Storno und Ablauf">
        <p class="aud-hinweis">
            Bestellungen, die storniert wurden oder abgelaufen sind, gemessen an allen Bestellungen der
            Veranstaltung. {{ number_format($storno['buyers_only_cancelled'], 0, ',', '.') }} Personen haben
            in dieser Auswahl ausschließlich solche Bestellungen und fehlen deshalb in der Käuferliste.
        </p>

        <div class="rpt-wrap">
            <table class="rpt">
                <thead>
                    <tr>
                        <th>Veranstaltung</th>
                        <th class="num">Storniert oder abgelaufen</th>
                        <th class="num">Bestellungen</th>
                        <th class="num">Anteil</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($storno['by_event'] as $slug => $zahlen)
                        <tr>
                            <td class="lbl">{{ $this->eventLabel($slug) }}</td>
                            <td class="num">{{ number_format($zahlen['cancelled'], 0, ',', '.') }}</td>
                            <td class="num">{{ number_format($zahlen['total'], 0, ',', '.') }}</td>
                            <td class="num">{{ number_format($zahlen['share'], 1, ',', '.') }}&nbsp;%</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="rpt-empty">Für diese Auswahl liegen keine Bestellungen vor.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @if ($fragen !== [])
        <x-filament::section heading="Antworten aus den Bestellungen">
            @foreach ($fragen as $frage)
                <h4 class="aud-klein aud-abstand">{{ $frage['question'] }}</h4>
                @include('filament.pages.partials.audience-verteilung', ['rows' => $frage['answers'], 'labelHeading' => 'Antwort'])
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
