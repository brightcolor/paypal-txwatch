<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        /* No @page margin rule here: it fights Chromium's print margins
           (Browsershot ->margins()), which are the single source of truth so
           EVERY page - including continuation pages - gets the same frame. */
        :root { --accent: {{ $accent_color ?? "#1d4ed8" }}; }
        * { box-sizing: border-box; }
        /* Page frame: the <thead> of this wrapper table repeats the document
           header at the top of EVERY printed page of the content flow (the
           cover sits before it, so it stays header-free). */
        table.page-frame { width: 100%; border-collapse: collapse; }
        table.page-frame > thead { display: table-header-group; }
        table.page-frame > thead th { text-align: left; font-weight: normal; padding: 0; }
        table.page-frame > tbody > tr > td { padding: 0; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            color: #1f2933;
            margin: 0;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--accent);
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        h1 { font-size: 18px; margin: 0 0 2px 0; color: var(--accent); }
        h2 { font-size: 12px; margin: 0; font-weight: normal; color: #52606d; }
        .meta { text-align: right; font-size: 10px; color: #52606d; }
        .event-box {
            background: #f0f4f8;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 14px;
            font-size: 10.5px;
        }
        .event-box strong { color: var(--accent); }
        .description { margin-bottom: 12px; font-size: 10.5px; color: #334155; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.data thead { display: table-header-group; }
        table.data tr { page-break-inside: avoid; }
        table.data th {
            background: var(--accent);
            color: #fff;
            font-size: 9.5px;
            text-align: left;
            padding: 5px 6px;
        }
        table.data td {
            font-size: 9.5px;
            padding: 4px 6px;
            border-bottom: 1px solid #e2e8f0;
        }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        td.numeric, th.numeric { text-align: right; }
        .group-heading {
            font-size: 11px;
            font-weight: bold;
            color: var(--accent);
            margin: 14px 0 4px 0;
        }
        .group-sum td {
            font-weight: bold;
            background: #eef2ff !important;
            border-top: 1px solid #94a3b8;
        }
        .grand-total {
            margin-top: 16px;
            display: flex;
            gap: 10px;
        }
        .grand-total .box {
            flex: 1;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 8px 10px;
            text-align: center;
        }
        .grand-total .box .label { font-size: 9px; color: #64748b; }
        .grand-total .box .value { font-size: 14px; font-weight: bold; color: var(--accent); }
        .footer-note {
            margin-top: 18px;
            font-size: 9px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 6px;
        }
        /* Frame comes from the real print margins; padding-emulated margins
           only ever framed the first/last page of the flow. */
        .content { padding: 0; }
        /* Event cover page */
        /* Height = printable area (297mm - 16mm top - 20mm bottom margin) with
           a little slack, so the cover fills exactly one page. */
        .cover { height: 257mm; display: flex; flex-direction: column; page-break-after: always; }
        .cover-hero { text-align: center; padding-top: 14mm; }
        .cover-hero img {
            max-width: 150mm; max-height: 80mm; object-fit: contain;
            border-radius: 8px; box-shadow: 0 6px 18px rgba(15, 23, 42, .22);
            border: 1px solid #e2e8f0; background: #fff; padding: 3mm;
        }
        .cover-guests { margin: 8mm auto 0; width: 150mm; }
        .cover-guests h3 { font-size: 13px; color: var(--accent); margin: 0 0 3px 0; text-transform: uppercase; letter-spacing: .05em; }
        .cover-guests table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
        .cover-guests th { text-align: left; color: #64748b; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; border-bottom: 2px solid #dbe3ec; padding: 3px 6px; }
        .cover-guests th.num, .cover-guests td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .cover-guests td { padding: 3.5px 6px; border-bottom: 1px solid #eef2f7; }
        .cover-guests tr.total td { border-top: 2px solid #dbe3ec; border-bottom: none; font-weight: bold; }
        .cover-guests .quote-good { color: #16a34a; }
        .cover-guests .quote-bad { color: #dc2626; }
        .cover-title { margin-top: 12mm; text-align: center; }
        .cover-title h1 { font-size: 30px; color: var(--accent); margin: 0 0 6px 0; }
        .cover-title .cust { font-size: 14px; color: #52606d; }
        .cover-facts { margin: 10mm auto 0; width: 140mm; }
        .cover-facts .fact { display: flex; padding: 6px 0; border-bottom: 1px solid #e2e8f0; font-size: 12.5px; }
        /* Fixed, non-shrinking label column so every value starts at the same
           x-position regardless of label length (long labels like
           "Veranstaltungsdatum" used to push their value further right). */
        .cover-facts .fact .k { flex: 0 0 52mm; color: #64748b; font-weight: bold; }
        .cover-facts .fact span:last-child { flex: 1; text-align: left; }
        .cover-desc { margin: 8mm auto 0; width: 130mm; font-size: 11.5px; color: #334155; line-height: 1.5; }
        .cover-foot { margin-top: auto; text-align: center; font-size: 10px; color: #94a3b8; }
    </style>
</head>
<body>
@if ($event)
    <div class="content cover">
        <div class="cover-hero">
            @if ($event->logo_path && file_exists(storage_path('app/public/' . $event->logo_path)))
                <img src="{{ storage_path('app/public/' . $event->logo_path) }}" alt="Event">
            @endif
        </div>
        <div class="cover-title">
            <h1>{{ $event->displayName() }}</h1>
            @if ($event->customer)<div class="cust">Abrechnung für {{ $event->customer->name }}</div>@endif
        </div>
        @php($pc = $pretix_cover ?? null)
        @php($pcd = $pc['details'] ?? [])
        <div class="cover-facts">
            @if (($pcd['date_from'] ?? null))
                <div class="fact"><span class="k">Beginn</span><span>{{ \Illuminate\Support\Carbon::parse($pcd['date_from'])->timezone(config('app.timezone'))->translatedFormat('l, d. F Y, H:i') }} Uhr</span></div>
            @elseif ($event->event_date)
                <div class="fact"><span class="k">Veranstaltungsdatum</span><span>{{ $event->event_date->translatedFormat('l, d. F Y') }}</span></div>
            @endif
            @if (($pcd['date_admission'] ?? null))
                <div class="fact"><span class="k">Einlass</span><span>{{ \Illuminate\Support\Carbon::parse($pcd['date_admission'])->timezone(config('app.timezone'))->format('H:i') }} Uhr</span></div>
            @endif
            @if (($pcd['location'] ?? null) || $event->venue)
                <div class="fact"><span class="k">Ort</span><span>{{ $pcd['location'] ?? $event->venue }}</span></div>
            @endif
            @if (($pcd['presale_start'] ?? null) || ($pcd['presale_end'] ?? null))
                <div class="fact"><span class="k">Vorverkauf</span><span>{{ ($pcd['presale_start'] ?? null) ? \Illuminate\Support\Carbon::parse($pcd['presale_start'])->format('d.m.Y') : '–' }} – {{ ($pcd['presale_end'] ?? null) ? \Illuminate\Support\Carbon::parse($pcd['presale_end'])->format('d.m.Y') : 'offen' }}</span></div>
            @endif
            @if ($pc && ($pc['capacity']['capacity'] ?? null))
                <div class="fact"><span class="k">Kapazität</span><span>{{ number_format($pc['capacity']['sold'], 0, ',', '.') }} von {{ number_format($pc['capacity']['capacity'], 0, ',', '.') }} Plätzen ({{ number_format($pc['capacity']['sold'] / max($pc['capacity']['capacity'], 1) * 100, 1, ',', '.') }} % Auslastung)</span></div>
            @endif
            @if ($event->contact_person)
                <div class="fact"><span class="k">Ansprechpartner</span><span>{{ $event->contact_person }}</span></div>
            @endif
            <div class="fact"><span class="k">Zahlungszeitraum</span><span>{{ optional($period['from'])->format('d.m.Y') ?? '–' }} – {{ optional($period['to'])->format('d.m.Y') ?? '–' }}</span></div>
            <div class="fact"><span class="k">Transaktionen</span><span>{{ $grand_total['count'] ?? 0 }}</span></div>
        </div>

        @if ($pc && ! empty($pc['categories']))
            <div class="cover-guests">
                <h3>Gästebilanz</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Kategorie</th>
                            <th class="num">Gebucht</th>
                            <th class="num">Erschienen</th>
                            <th class="num">Erscheinungsquote</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pc['categories'] as $cat)
                            <tr>
                                <td>{{ $cat['name'] }}</td>
                                <td class="num">{{ number_format($cat['booked'], 0, ',', '.') }}</td>
                                <td class="num">{{ number_format($cat['attended'], 0, ',', '.') }}</td>
                                <td class="num">{{ $cat['ratio'] !== null ? number_format($cat['ratio'], 1, ',', '.') . ' %' : '–' }}</td>
                            </tr>
                        @endforeach
                        <tr class="total">
                            <td>Gesamt</td>
                            <td class="num">{{ number_format($pc['totals']['booked'], 0, ',', '.') }}</td>
                            <td class="num">{{ number_format($pc['totals']['attended'], 0, ',', '.') }}</td>
                            <td class="num {{ ($pc['totals']['show_up_ratio'] ?? 0) >= 80 ? 'quote-good' : 'quote-bad' }}">
                                {{ $pc['totals']['show_up_ratio'] !== null ? number_format($pc['totals']['show_up_ratio'], 1, ',', '.') . ' %' : '–' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                @if (($pc['totals']['no_shows'] ?? 0) > 0)
                    <div style="font-size: 10px; color: #94a3b8; margin-top: 2mm;">
                        {{ number_format($pc['totals']['no_shows'], 0, ',', '.') }} gebuchte Gäste sind nicht erschienen (No-Shows).
                        Stand: Check-in-Daten aus pretix zum Erstellungszeitpunkt.
                    </div>
                @endif
            </div>
        @endif

        @if ($event->short_description)
            <div class="cover-desc">{{ $event->short_description }}</div>
        @endif
        @php($brand = rescue(fn () => \App\Models\BrandSetting::current(), null, false))
        <div class="cover-foot">
            @if ($brand?->logoAbsolutePath())
                <div style="margin-bottom: 2mm;">
                    <img src="{{ $brand->logoAbsolutePath() }}" alt="" style="max-height: 9mm; max-width: 45mm; object-fit: contain;">
                </div>
            @endif
            @if (filled($brand?->claim))
                <div style="margin-bottom: 1.5mm; color: #64748b;">{{ $brand->claim }}</div>
            @endif
            {{ $title }} &middot; erstellt am {{ $generated_at->format('d.m.Y H:i') }}
        </div>
    </div>
@endif
<div class="content">
<table class="page-frame">
    {{-- This <thead> repeats on every printed page of the content flow, so
         page 2 and ALL following pages carry the full styled document header.
         The cover page sits before this table and stays header-free. --}}
    <thead>
    <tr>
        <th>
            <div class="header">
                <div>
                    <h1>{{ $title }}</h1>
                    @if ($subtitle)
                        <h2>{{ $subtitle }}</h2>
                    @endif
                </div>
                <div class="meta">
                    @if ($period['from'] || $period['to'])
                        Zeitraum: {{ optional($period['from'])->format('d.m.Y') ?? '–' }}
                        – {{ optional($period['to'])->format('d.m.Y') ?? '–' }}<br>
                    @endif
                    Erstellt am {{ $generated_at->format('d.m.Y H:i') }}
                    @if ($mode === 'internal')
                        <br><strong>Interner Modus</strong>
                    @endif
                </div>
            </div>
        </th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td>

    @if ($event)
        <div class="event-box">
            <strong>{{ $event->displayName() }}</strong>
            @if ($event->event_date) &middot; {{ $event->event_date->format('d.m.Y') }} @endif
            @if ($event->venue) &middot; {{ $event->venue }} @endif
            @if ($event->customer) &middot; {{ $event->customer->name }} @endif
            @if ($event->short_description)
                <br>{{ $event->short_description }}
            @endif
        </div>
    @endif

    @if ($description)
        <div class="description">{{ $description }}</div>
    @endif

    @foreach ($groups as $group)
        @if ($group['label'] !== '')
            <div class="group-heading">{{ $group['label'] }}</div>
        @endif

        <table class="data">
            <thead>
            <tr>
                @foreach ($column_labels as $key => $label)
                    <th @class(['numeric' => \App\Services\Export\ExportColumns::isNumeric($columns[$key])])>{{ $label }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($group['rows'] as $row)
                <tr>
                    @foreach ($row as $col => $value)
                        <td @class(['numeric' => \App\Services\Export\ExportColumns::isNumeric($col)])>
                            {{ \App\Services\Export\ExportColumns::isNumeric($col) ? number_format((float) $value, 2, ',', '.') : $value }}
                        </td>
                    @endforeach
                </tr>
            @endforeach

            @if ($group['sum'])
                <tr class="group-sum">
                    <td colspan="{{ count($columns) }}">
                        Summe ({{ $group['sum']['count'] }} Transaktionen)
                        &nbsp;&middot;&nbsp; Brutto: {{ number_format($group['sum']['gross'], 2, ',', '.') }}
                        &nbsp;&middot;&nbsp; Netto (o. MwSt): {{ number_format($group['sum']['net_excl_vat'], 2, ',', '.') }}
                        &nbsp;&middot;&nbsp; MwSt: {{ number_format($group['sum']['vat'], 2, ',', '.') }}
                        &nbsp;&middot;&nbsp; Gebühr: {{ number_format($group['sum']['fee'], 2, ',', '.') }}
                        &nbsp;&middot;&nbsp; Nach Gebühren: {{ number_format($group['sum']['net'], 2, ',', '.') }}
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    @endforeach

    @if ($grand_total)
        <div class="grand-total">
            <div class="box"><div class="label">Transaktionen</div><div class="value">{{ $grand_total['count'] }}</div></div>
            <div class="box"><div class="label">Brutto</div><div class="value">{{ number_format($grand_total['gross'], 2, ',', '.') }}</div></div>
            <div class="box"><div class="label">Netto (o. MwSt)</div><div class="value">{{ number_format($grand_total['net_excl_vat'], 2, ',', '.') }}</div></div>
            <div class="box"><div class="label">MwSt</div><div class="value">{{ number_format($grand_total['vat'], 2, ',', '.') }}</div></div>
            <div class="box"><div class="label">Gebühren</div><div class="value">{{ number_format($grand_total['fee'], 2, ',', '.') }}</div></div>
            <div class="box"><div class="label">Nach Gebühren</div><div class="value">{{ number_format($grand_total['net'], 2, ',', '.') }}</div></div>
        </div>
    @endif

    @if ($event?->legal_notice)
        <div class="footer-note">{{ $event->legal_notice }}</div>
    @endif

    @if ($footer_note)
        <div class="footer-note">{{ $footer_note }}</div>
    @endif

    @if ($event?->pdf_footer)
        <div class="footer-note">{{ $event->pdf_footer }}</div>
    @endif

        </td>
    </tr>
    </tbody>
</table>
</div>
</body>
</html>
