<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Contract #{{ $contract->id }}</title>
    <style>
        @page {
            margin: 32px 36px;
        }

        body {
            color: #1f2933;
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.45;
        }

        h1, h2, h3, p {
            margin: 0;
        }

        .header {
            border-bottom: 2px solid #1f2933;
            margin-bottom: 24px;
            padding-bottom: 16px;
        }

        .header-table,
        .meta-table,
        .items-table {
            border-collapse: collapse;
            width: 100%;
        }

        .header-table td {
            vertical-align: bottom;
            width: 50%;
        }

        .right {
            text-align: right;
        }

        .muted {
            color: #68778d;
        }

        .section-title {
            border-bottom: 1px solid #d8dee7;
            color: #52606d;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: .04em;
            margin: 22px 0 10px;
            padding-bottom: 5px;
            text-transform: uppercase;
        }

        .meta-table td {
            padding: 3px 0;
            vertical-align: top;
            width: 50%;
        }

        .items-table th {
            background: #f3f6fa;
            border: 1px solid #d8dee7;
            font-size: 11px;
            padding: 7px;
            text-align: left;
        }

        .items-table td {
            border: 1px solid #d8dee7;
            padding: 7px;
            vertical-align: top;
        }

        .items-table .number {
            text-align: right;
            white-space: nowrap;
        }

        .total-row th,
        .total-row td {
            background: #f8fafc;
            font-weight: bold;
        }

        .pre-wrap {
            white-space: pre-wrap;
        }

        .badge {
            border: 1px solid #b8c4d1;
            border-radius: 3px;
            color: #405261;
            display: inline-block;
            font-size: 10px;
            padding: 2px 5px;
        }

        .footer {
            border-top: 1px solid #d8dee7;
            color: #68778d;
            font-size: 10px;
            margin-top: 28px;
            padding-top: 10px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                <td>
                    <h1>Contract / Quote</h1>
                    <p class="muted">Reference: #{{ $contract->id }}</p>
                    @if($contract->description)
                        <p>{{ $contract->description }}</p>
                    @endif
                </td>
                <td class="right">
                    <h2>{{ $contract->client->name }}</h2>
                    @if($contract->client->client_number)
                        <p class="muted">Client ID: {{ $contract->client->client_number }}</p>
                    @endif
                    @if($contract->client->org_no)
                        <p class="muted">Org no: {{ $contract->client->org_no }}</p>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <table class="meta-table">
        <tr>
            <td>
                <div class="section-title">Contract Dates</div>
                <p><span class="muted">Start date:</span> <strong>{{ $contract->start_date?->format('d.m.Y') ?? 'TBA' }}</strong></p>
                @if($contract->end_date)
                    <p><span class="muted">End date:</span> <strong>{{ $contract->end_date->format('d.m.Y') }}</strong></p>
                @endif
                @if($contract->binding_end_date)
                    <p><span class="muted">Binding until:</span> <strong>{{ $contract->binding_end_date->format('d.m.Y') }}</strong></p>
                @endif
            </td>
            <td>
                <div class="section-title">Status</div>
                <p><strong>{{ ucwords(str_replace('_', ' ', $contract->approval_status)) }}</strong></p>
                @if($contract->sla)
                    <p class="muted">Contract SLA: {{ $contract->sla->name }}</p>
                @endif
            </td>
        </tr>
    </table>

    <div class="section-title">Included Services</div>
    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th>SLA</th>
                <th>Rates</th>
                <th class="number">Qty</th>
                <th class="number">Unit Price</th>
                <th class="number">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($contract->items as $item)
                <tr>
                    <td>
                        <strong>{{ $item->name }}</strong>
                        @if($item->sku)
                            <div class="muted">{{ $item->sku }}</div>
                        @endif
                    </td>
                    <td>
                        @if($item->uses_contract_default_sla)
                            <span class="badge">Contract default</span>
                            <div class="muted">{{ $contract->sla?->name ?? 'System default' }}</div>
                        @else
                            {{ $item->sla_snapshot['name'] ?? $item->slaPolicy?->name ?? 'Custom SLA' }}
                        @endif
                    </td>
                    <td>
                        @forelse($item->timeRates->where('is_active', true) as $rate)
                            <div>
                                <strong>{{ $rate->name }}</strong>
                                <span class="muted">{{ number_format((float) $rate->amount_ex_vat, 2, ',', ' ') }} {{ $rate->currency }}/{{ $rate->unit }}</span>
                            </div>
                        @empty
                            <span class="muted">No rates</span>
                        @endforelse
                    </td>
                    <td class="number">{{ (int) $item->quantity }} {{ $item->unit }}</td>
                    <td class="number">{{ number_format((float) $item->unit_price, 2, ',', ' ') }} NOK</td>
                    <td class="number"><strong>{{ number_format((float) $item->line_total, 2, ',', ' ') }} NOK</strong></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <th colspan="5" class="number">Recurring monthly amount, ex VAT</th>
                <td class="number">{{ number_format((float) $contract->total_monthly_amount, 2, ',', ' ') }} NOK</td>
            </tr>
        </tfoot>
    </table>

    @if($contract->terms_snapshot)
        <div class="section-title">Terms & Conditions</div>
        <div class="pre-wrap">{{ $contract->terms_snapshot }}</div>
    @endif

    @if($contract->dpa_snapshot)
        <div class="section-title">Data Processing Agreement</div>
        <div class="pre-wrap">{{ $contract->dpa_snapshot }}</div>
    @endif

    @if($contract->legal_snapshot)
        <div class="section-title">Legal</div>
        <div class="pre-wrap">{{ $contract->legal_snapshot }}</div>
    @endif

    @if($contract->sla_snapshot)
        <div class="section-title">SLA Snapshot</div>
        <div class="pre-wrap">{{ $contract->sla_snapshot }}</div>
    @endif

    @if($contract->approval_status === 'won')
        <div class="section-title">Acceptance</div>
        <p>
            Accepted by {{ $contract->accepted_by_name ?? 'Internal Approval' }}
            @if($contract->accepted_at)
                on {{ $contract->accepted_at->format('d.m.Y H:i') }}
            @endif
        </p>
    @endif

    <div class="footer">
        <p>{{ $companyProfile['company_name'] ?? config('app.name', 'Nexum PSA') }} - Generated {{ now()->format('d.m.Y H:i') }}</p>
    </div>
</body>
</html>
