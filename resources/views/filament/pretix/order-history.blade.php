{{--
    Der Verlauf einer pretix-Bestellung: was der Import gefunden hat und was
    daraufhin mit ihr geschah.

    Älteste Zeile zuerst – es wird als Verlauf gelesen, nicht als Statusanzeige.
--}}
<div class="rpt-wrap">
    <table class="rpt" style="min-width: 34rem;">
        <tbody>
            <tr>
                <td class="lbl">Bestellung</td>
                <td>
                    <strong>{{ $order->order_code }}</strong>
                    <span class="text-xs text-gray-400">· Event {{ $order->event_slug }}</span>
                </td>
            </tr>
            <tr>
                <td class="lbl">Status</td>
                <td><strong>{{ \App\Models\PretixOrderLogEntry::statusLabel($order->status) }}</strong></td>
            </tr>
            <tr>
                <td class="lbl">Betrag</td>
                <td>{{ number_format((float) $order->total, 2, ',', '.') }} {{ $order->currency }}</td>
            </tr>
            <tr>
                <td class="lbl">Zahlungsart</td>
                <td>{{ $order->payment_provider ?: '–' }}</td>
            </tr>
        </tbody>
    </table>
</div>

<div class="mt-4">
    <p class="text-sm"><strong>Verlauf</strong></p>
    <div class="rpt-wrap mt-2">
        <table class="rpt" style="min-width: 34rem;">
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td class="lbl" style="white-space: nowrap;">{{ $entry->at?->format('d.m.Y H:i') }}</td>
                        <td style="white-space: nowrap;">
                            {{-- Grün, was Geld bewegt hat; rot, was bewusst liegen blieb. --}}
                            <span class="{{ in_array($entry->action, ['booked', 'reconciled'], true) ? 'net' : (in_array($entry->action, ['skipped', 'found_changed'], true) ? 'neg' : '') }}">
                                {{ $entry->actionLabel() }}
                            </span>
                        </td>
                        <td style="white-space: normal;">{{ $entry->message }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="muted">
                            {{-- Ehrlich statt beschönigend: der Bestand vor Einführung der
                                 Aufzeichnung hat keinen Verlauf, und das ist kein Fehler. --}}
                            Noch kein Verlauf – diese Bestellung wurde vor Einführung der Aufzeichnung
                            importiert. Ab dem nächsten Import, der sie anfasst, steht hier etwas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
