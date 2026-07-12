<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Abrechnung {{ $event->displayName() }}</title>
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #1f2933; margin: 0; }
        .content { padding: 15mm 12mm; }
        .header { border-bottom: 2px solid #1d4ed8; padding-bottom: 10px; margin-bottom: 16px; display: flex; justify-content: space-between; }
        h1 { font-size: 19px; margin: 0 0 2px 0; color: #1d4ed8; }
        h2 { font-size: 12px; margin: 0; font-weight: normal; color: #52606d; }
        .meta { text-align: right; font-size: 10px; color: #52606d; }
        table.blocks { width: 100%; border-collapse: collapse; margin: 14px 0; }
        table.blocks th { background: #1d4ed8; color: #fff; font-size: 10px; text-align: left; padding: 6px 8px; }
        table.blocks td { font-size: 10.5px; padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        td.num, th.num { text-align: right; }
        tr.total td { font-weight: bold; background: #eef2ff; border-top: 2px solid #1d4ed8; }
        .payout { margin-top: 18px; border: 2px solid #1d4ed8; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; }
        .payout .label { font-size: 13px; font-weight: bold; }
        .payout .value { font-size: 20px; font-weight: bold; color: #1d4ed8; }
        .vat { margin-top: 14px; font-size: 10px; color: #334155; }
        .vat table { border-collapse: collapse; }
        .vat td { padding: 2px 14px 2px 0; }
        .footer-note { margin-top: 20px; font-size: 9px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 6px; }
    </style>
</head>
<body>
<div class="content">
    <div class="header">
        <div>
            {{-- No logo in the header (matches the export PDF; looked cluttered). --}}
            <h1>{{ $title }}</h1>
            <h2>
                @if (($customer ?? null)) für {{ $customer->name }}
                @elseif ($event && $event->customer) für {{ $event->customer->name }} @endif
                @if ($event && $event->event_date) &middot; Veranstaltung am {{ $event->event_date->format('d.m.Y') }} @endif
                @if ($event && $event->venue) &middot; {{ $event->venue }} @endif
            </h2>
        </div>
        <div class="meta">
            @if ($period['from'] || $period['to'])
                Zahlungen von {{ optional($period['from'])->format('d.m.Y') ?? '–' }}
                bis {{ optional($period['to'])->format('d.m.Y') ?? '–' }}<br>
            @endif
            Erstellt am {{ $generated_at->format('d.m.Y H:i') }}
        </div>
    </div>

    @if (! empty($events))
        <table class="blocks">
            <thead><tr><th>Veranstaltung</th><th class="num">Transaktionen</th><th class="num">Betrag</th><th class="num">Auszahlung</th></tr></thead>
            <tbody>
            @foreach ($events as $ev)
                <tr>
                    <td>{{ $ev['label'] }}</td>
                    <td class="num">{{ $ev['count'] }}</td>
                    <td class="num">{{ number_format($ev['amount'], 2, ',', '.') }} €</td>
                    <td class="num">{{ number_format($ev['payout'], 2, ',', '.') }} €</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <table class="blocks">
        <thead>
        <tr>
            <th>Position</th>
            <th class="num">Anzahl</th>
            <th class="num">Betrag</th>
            <th class="num">Gebühren</th>
            <th class="num">Nach Gebühren</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($blocks as $block)
            <tr>
                <td>{{ $block['label'] }}</td>
                <td class="num">{{ $block['count'] }}</td>
                <td class="num">{{ number_format($block['amount'], 2, ',', '.') }} €</td>
                <td class="num">{{ number_format($block['fees'], 2, ',', '.') }} €</td>
                <td class="num">{{ number_format($block['net'], 2, ',', '.') }} €</td>
            </tr>
        @endforeach
        <tr class="total">
            <td>Gesamt</td>
            <td class="num">{{ $totals['count'] }}</td>
            <td class="num">{{ number_format($totals['amount'], 2, ',', '.') }} €</td>
            <td class="num">{{ number_format($totals['fees'], 2, ',', '.') }} €</td>
            <td class="num">{{ number_format($totals['payout'], 2, ',', '.') }} €</td>
        </tr>
        </tbody>
    </table>

    <div class="payout">
        <div class="label">Auszahlungsbetrag</div>
        <div class="value">{{ number_format($totals['payout'], 2, ',', '.') }} €</div>
    </div>

    <div class="vat">
        <strong>Umsatzsteuer-Ausweis</strong> (echte MwSt aus pretix, wo verknüpft; sonst {{ \App\Services\Export\ExportColumns::formatRate($vat_rate) }}% angenommen)
        <table>
            <tr><td>Brutto</td><td>{{ number_format($totals['amount'], 2, ',', '.') }} €</td></tr>
            <tr><td>Netto (o. MwSt)</td><td>{{ number_format($totals['net_excl_vat'], 2, ',', '.') }} €</td></tr>
            <tr><td>MwSt</td><td>{{ number_format($totals['vat'], 2, ',', '.') }} €</td></tr>
        </table>
    </div>

    @if ($event->legal_notice)
        <div class="footer-note">{{ $event->legal_notice }}</div>
    @endif
    <div class="footer-note">
        Basis: alle dem Event zugeordneten Zahlungen und Erstattungen (PayPal-Sync und pretix-Import) zum
        Erstellzeitpunkt; interne PayPal-Kontobewegungen (Reserven, Auszahlungen) sind nicht enthalten.
    </div>
    @if ($event->pdf_footer)
        <div class="footer-note">{{ $event->pdf_footer }}</div>
    @endif
</div>
</body>
</html>
