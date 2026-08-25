<!DOCTYPE html>
<html lang="nb">
<head>
    <meta charset="UTF-8">
    <title>{{ $customerDocument['document']['type'] }} #{{ $customerDocument['document']['contract_number'] }}</title>
    <style>
        @page { margin: 42px 34px 58px; }
        * { box-sizing: border-box; }
        body {
            color: #1f2933;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.2px;
            line-height: 1.42;
        }
        h1, h2, h3, p { margin: 0; }
        h1 { font-size: 23px; }
        h2 { font-size: 14px; }
        h3 { font-size: 11px; }
        table { border-collapse: collapse; width: 100%; }
        td, th { overflow-wrap: anywhere; word-wrap: break-word; }
        .party-table tr, .meta-table tr, .totals-table tr, .rate-table tr { page-break-inside: avoid; }
        .header { border-bottom: 2px solid #243447; margin-bottom: 18px; padding-bottom: 13px; }
        .header td { vertical-align: top; width: 50%; }
        .right { text-align: right; }
        .muted { color: #65758b; }
        .small { font-size: 8.8px; }
        .section-title {
            border-bottom: 1px solid #cfd8e3;
            color: #405261;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: .04em;
            margin: 18px 0 8px;
            padding-bottom: 4px;
            text-transform: uppercase;
        }
        .party-table td { border: 1px solid #d8e0e9; padding: 8px; vertical-align: top; width: 50%; }
        .meta-table td { padding: 2px 5px 2px 0; vertical-align: top; width: 25%; }
        .items-table { table-layout: fixed; }
        .items-table th {
            background: #eef2f6;
            border: 1px solid #cfd8e3;
            font-size: 8.3px;
            padding: 6px 4px;
            text-align: left;
        }
        .items-table td {
            border: 1px solid #d8e0e9;
            padding: 6px 4px;
            vertical-align: top;
        }
        .items-table th:nth-child(1), .items-table td:nth-child(1) { width: 16%; }
        .items-table th:nth-child(2), .items-table td:nth-child(2) { width: 27%; }
        .items-table th:nth-child(3), .items-table td:nth-child(3) { width: 13%; }
        .items-table th:nth-child(4), .items-table td:nth-child(4) { width: 14%; }
        .items-table th:nth-child(5), .items-table td:nth-child(5) { width: 16%; }
        .items-table th:nth-child(6), .items-table td:nth-child(6) { width: 14%; }
        .number { text-align: right; white-space: nowrap; }
        .pre-wrap { white-space: pre-wrap; overflow-wrap: anywhere; word-wrap: break-word; }
        .totals-table { margin-left: auto; margin-top: 10px; width: 48%; }
        .totals-table th, .totals-table td { border-bottom: 1px solid #d8e0e9; padding: 5px; }
        .totals-table th { text-align: left; }
        .totals-table td { font-weight: bold; text-align: right; white-space: nowrap; }
        .rate-table td { border-bottom: 1px solid #e2e8f0; padding: 5px 2px; }
        .box { border: 1px solid #cfd8e3; padding: 10px; }
        .approval { margin-top: 20px; page-break-inside: avoid; }
        .signature-line { border-top: 1px solid #6b7785; display: inline-block; margin-top: 30px; padding-top: 4px; width: 46%; }
        .appendix { page-break-before: always; }
        .appendix-meta { color: #65758b; font-size: 9px; margin: 4px 0 14px; }
    </style>
</head>
<body>
    <!-- First-page document identity -->
    <div class="header">
        <table>
            <tr>
                <td>
                    <h1>{{ $customerDocument['document']['type'] }}</h1>
                    <div class="muted">Kontraktsnummer {{ $customerDocument['document']['contract_number'] }}</div>
                    @if(filled($customerDocument['description']))
                        <div class="pre-wrap" style="margin-top: 5px;">{{ $customerDocument['description'] }}</div>
                    @endif
                </td>
                <td class="right">
                    <h2>{{ $customerDocument['parties']['customer']['name'] }}</h2>
                    <div class="muted">{{ $customerDocument['document']['status'] }}</div>
                    <div class="muted">Dokumentdato {{ $customerDocument['document']['generated_date'] }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title">Avtalepartene</div>
    <table class="party-table">
        <tr>
            @foreach($customerDocument['parties'] as $party)
                <td>
                    <div class="small muted">{{ $party['label'] }}</div>
                    <strong>{{ $party['name'] }}</strong>
                    @if(filled($party['organization_number']))
                        <div>Org.nr. {{ $party['organization_number'] }}</div>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>

    <div class="section-title">Avtaleperiode</div>
    <table class="meta-table">
        <tr>
            @foreach($customerDocument['dates'] as $date)
                @if(filled($date['value']))
                    <td>
                        <div class="small muted">{{ $date['label'] }}</div>
                        <strong>{{ $date['value'] }}</strong>
                    </td>
                @endif
            @endforeach
        </tr>
    </table>

    <div class="section-title">Tjenester</div>
    <table class="items-table">
        <thead>
            <tr>
                @foreach(array_keys(\App\Modules\Commercial\Support\ContractCustomerDocument::COLUMNS) as $columnKey)
                    <th>{{ $customerDocument['columns'][$columnKey] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($customerDocument['lines'] as $line)
                <tr>
                    <td><strong>{{ $line['service'] }}</strong></td>
                    <td class="pre-wrap">{{ $line['short_description'] }}</td>
                    <td>{{ $line['scope'] }}</td>
                    <td class="number">{{ $line['unit_price']['display'] }}</td>
                    <td>
                        {{ $line['billing']['label'] }}
                        @if(($line['billing']['setup_fee']['minor'] ?? 0) > 0)
                            <div class="small muted">Oppstart: {{ $line['billing']['setup_fee']['display'] }}</div>
                        @endif
                    </td>
                    <td class="number"><strong>{{ $line['total']['display'] }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        @foreach([
            'monthly' => 'Månedlig beløp eks. mva.',
            'quarterly' => 'Kvartalsbeløp eks. mva.',
            'yearly' => 'Årlig beløp eks. mva.',
            'one_time' => 'Engangsbeløp eks. mva.',
        ] as $cadence => $label)
            @if(($customerDocument['totals'][$cadence]['minor'] ?? 0) > 0)
                <tr>
                    <th>{{ $label }}</th>
                    <td>{{ $customerDocument['totals'][$cadence]['display'] }}</td>
                </tr>
            @endif
        @endforeach
    </table>

    @if($customerDocument['rates'])
        <div class="section-title">{{ $customerDocument['rates']['title'] }}</div>
        <table class="rate-table">
            @foreach($customerDocument['rates']['items'] as $rate)
                <tr>
                    <td>{{ $rate['name'] }}</td>
                    <td class="number"><strong>{{ $rate['display'] }}</strong></td>
                </tr>
            @endforeach
        </table>
    @endif

    @if($customerDocument['support'])
        <div class="section-title">{{ $customerDocument['support']['title'] }}</div>
        <div class="box pre-wrap">{{ $customerDocument['support']['content'] }}</div>
    @endif

    <div class="section-title">Godkjenning og signatur</div>
    <div class="box approval">
        <strong>{{ $customerDocument['approval']['title'] }}</strong>
        <div>{{ $customerDocument['approval']['text'] }}</div>
        @if(!$customerDocument['approval']['accepted'])
            <div>
                <span class="signature-line">Navn og signatur</span>
                <span class="signature-line" style="float: right;">Dato</span>
            </div>
        @endif
    </div>

    @foreach($customerDocument['appendices'] as $appendix)
        <section class="appendix">
            <h1>Vedlegg {{ $appendix['number'] }}</h1>
            <h2>{{ $appendix['title'] }}</h2>
            <div class="appendix-meta">Versjon {{ $appendix['version'] }} · Dato {{ $appendix['date'] }}</div>
            <div class="pre-wrap">{{ $appendix['content'] }}</div>
        </section>
    @endforeach
</body>
</html>
