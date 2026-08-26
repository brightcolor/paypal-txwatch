{{--
    Der Nachweis zu einer Zahlungsmeldung.

    Aufgebaut wie eine Beweiskette: was entschieden wurde, warum, auf welches Geld
    hin, und was pretix dazu gesagt hat. Der Verwendungszweck steht mit dabei, weil
    er das einzige ist, was die Bestellung identifiziert hat.
--}}
<div class="rpt-wrap">
    <table class="rpt" style="min-width: 34rem;">
        <tbody>
            <tr>
                <td class="lbl">Ergebnis</td>
                <td>
                    <strong class="{{ $record->succeeded() ? 'net' : ($record->outcome === \App\Models\PretixPaymentConfirmation::OUTCOME_FAILED ? 'neg' : '') }}">
                        {{ $record->outcomeLabel() }}
                    </strong>
                </td>
            </tr>
            <tr>
                <td class="lbl">Begründung</td>
                <td style="white-space: normal;">{{ $record->reasonText() }}</td>
            </tr>
            @if (filled($record->message) && $record->message !== $record->reasonText())
                <tr>
                    <td class="lbl">Im Wortlaut</td>
                    {{-- Die Antwort von pretix bzw. die genaue Zahl, an der es lag.
                         Die Begründung darüber ist die Regel, das hier der Einzelfall. --}}
                    <td style="white-space: normal;">{{ $record->message }}</td>
                </tr>
            @endif
            <tr>
                <td class="lbl">Bestellung</td>
                <td>
                    <strong>{{ $record->order_code ?: '–' }}</strong>
                    @if (filled($record->event_slug))
                        <span class="text-xs text-gray-400">· Event {{ $record->event_slug }}</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td class="lbl">Schalter am Event</td>
                {{-- Wer das hier liest, will als Nächstes wissen, wo man es abstellt. --}}
                <td style="white-space: normal;">
                    @if ($record->event)
                        {{ $record->event->name }} –
                        <strong>{{ $record->event->auto_mark_paid ? 'automatische Meldung ist an' : 'automatische Meldung ist aus' }}</strong>
                        <span class="text-xs text-gray-400">(Events → „Zahlungen automatisch melden")</span>
                    @else
                        <span class="muted">kein Event zugeordnet</span>
                    @endif
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div class="mt-4">
    <p class="text-sm"><strong>Das Geld, auf das hin entschieden wurde</strong></p>
    <div class="rpt-wrap mt-2">
        <table class="rpt" style="min-width: 34rem;">
            <tbody>
                <tr>
                    <td class="lbl">Betrag</td>
                    <td><span class="net">{{ number_format((float) $record->amount, 2, ',', '.') }} {{ $record->currency }}</span></td>
                </tr>
                <tr>
                    <td class="lbl">Gebucht</td>
                    <td>{{ $record->booked_on?->format('d.m.Y') ?? '–' }}</td>
                </tr>
                <tr>
                    <td class="lbl">Gegenseite</td>
                    <td>{{ $record->counterparty_name ?: '–' }}</td>
                </tr>
                <tr>
                    <td class="lbl">Verwendungszweck</td>
                    {{-- Der Text, der die Bestellnummer trug – ohne ihn ist die
                         Zuordnung nicht überprüfbar, sondern nur behauptet. --}}
                    <td style="white-space: normal;">{{ $record->purpose ?: '–' }}</td>
                </tr>
                <tr>
                    <td class="lbl">Herkunft</td>
                    <td>
                        {{ $record->source === \App\Models\PretixPaymentConfirmation::SOURCE_JOURNAL
                            ? 'Bankabruf (Enable Banking)' : 'Kontoumsatz' }}
                        @if ($record->journal_entry_id)
                            <span class="text-xs text-gray-400">· Journaleintrag #{{ $record->journal_entry_id }}</span>
                        @elseif ($record->bank_transaction_id)
                            <span class="text-xs text-gray-400">· Umsatz #{{ $record->bank_transaction_id }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="lbl">Ausgelöst durch</td>
                    <td>
                        {{ $record->automatic ? 'die Automatik' : ($record->user?->name ?? 'Handeingabe') }}
                        <span class="text-xs text-gray-400">· {{ $record->at?->format('d.m.Y H:i:s') }}</span>
                    </td>
                </tr>
                @if ($record->pretix_payment_local_id)
                    <tr>
                        <td class="lbl">pretix-Zahlung</td>
                        <td><code class="text-xs">#{{ $record->pretix_payment_local_id }}</code></td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</div>
