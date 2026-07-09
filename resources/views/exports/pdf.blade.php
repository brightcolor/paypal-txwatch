<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
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
            border-bottom: 2px solid #1d4ed8;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .header img.logo { max-height: 50px; max-width: 160px; object-fit: contain; }
        h1 { font-size: 18px; margin: 0 0 2px 0; color: #1d4ed8; }
        h2 { font-size: 12px; margin: 0; font-weight: normal; color: #52606d; }
        .meta { text-align: right; font-size: 10px; color: #52606d; }
        .event-box {
            background: #f0f4f8;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 14px;
            font-size: 10.5px;
        }
        .event-box strong { color: #1d4ed8; }
        .description { margin-bottom: 12px; font-size: 10.5px; color: #334155; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.data thead { display: table-header-group; }
        table.data tr { page-break-inside: avoid; }
        table.data th {
            background: #1d4ed8;
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
            color: #1d4ed8;
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
        .grand-total .box .value { font-size: 14px; font-weight: bold; color: #1d4ed8; }
        .footer-note {
            margin-top: 18px;
            font-size: 9px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 6px;
        }
        .content { padding: 15mm 10mm; }
    </style>
</head>
<body>
<div class="content">
    <div class="header">
        <div>
            @if ($event?->logo_path)
                <img class="logo" src="{{ storage_path('app/public/' . $event->logo_path) }}" alt="Logo">
            @endif
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
                        &nbsp;&middot;&nbsp; Gebühr: {{ number_format($group['sum']['fee'], 2, ',', '.') }}
                        &nbsp;&middot;&nbsp; Netto: {{ number_format($group['sum']['net'], 2, ',', '.') }}
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
            <div class="box"><div class="label">Gebühren</div><div class="value">{{ number_format($grand_total['fee'], 2, ',', '.') }}</div></div>
            <div class="box"><div class="label">Netto</div><div class="value">{{ number_format($grand_total['net'], 2, ',', '.') }}</div></div>
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
</div>
</body>
</html>
