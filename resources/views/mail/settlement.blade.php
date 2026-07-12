<x-mail::message>
# Abrechnung {{ $settlement->title }}

anbei erhalten Sie Ihre Abrechnung als PDF.

@if($settlement->period_from && $settlement->period_to)
**Zeitraum:** {{ $settlement->period_from->format('d.m.Y') }} – {{ $settlement->period_to->format('d.m.Y') }}
@endif

**Auszahlungsbetrag:** {{ number_format((float) $settlement->payout, 2, ',', '.') }} €

Bei Rückfragen antworten Sie einfach auf diese E-Mail.

Mit freundlichen Grüßen
TxWatch
</x-mail::message>
