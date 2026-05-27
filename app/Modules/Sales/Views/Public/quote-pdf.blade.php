<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1 { font-size: 24px; margin-bottom: 4px; }
        h2 { font-size: 15px; margin-top: 22px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 7px 5px; vertical-align: top; }
        th { text-align: left; background: #f9fafb; }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        .summary { width: 45%; margin-left: auto; margin-top: 18px; }
        .summary td { border: 0; }
    </style>
</head>
<body>
    <h1>{{ $version->title }}</h1>
    <div class="muted">{{ $version->quote->quote_key }} v{{ $version->version_number }} / {{ $opportunity->client?->name }}</div>
    <div class="muted">Expires {{ $version->expires_at?->format('d.m.Y') ?: 'not set' }}</div>

    @foreach(['intro_text', 'scope_text', 'assumptions_text', 'exclusions_text', 'next_steps_text'] as $field)
        @if(filled($version->{$field}))
            <h2>{{ ucwords(str_replace('_', ' ', str_replace('_text', '', $field))) }}</h2>
            <p>{!! nl2br(e($version->{$field})) !!}</p>
        @endif
    @endforeach

    <h2>Quote Lines</h2>
    <table>
        <thead>
        <tr>
            <th>Description</th>
            <th class="right">Qty</th>
            <th class="right">Unit ex VAT</th>
            <th class="right">Total ex VAT</th>
        </tr>
        </thead>
        <tbody>
        @foreach($version->lines as $line)
            <tr>
                <td>
                    <strong>{{ $line->name }}</strong><br>
                    <span class="muted">{{ $line->description }}</span>
                </td>
                <td class="right">{{ $line->quantity }}</td>
                <td class="right">{{ number_format((float) $line->unit_price_ex_vat, 2, ',', ' ') }}</td>
                <td class="right">{{ number_format((float) $line->line_total_ex_vat, 2, ',', ' ') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td>Subtotal ex VAT</td>
            <td class="right">{{ number_format((float) $version->total_ex_vat, 2, ',', ' ') }}</td>
        </tr>
        <tr>
            <td>VAT</td>
            <td class="right">{{ number_format((float) $version->vat_total, 2, ',', ' ') }}</td>
        </tr>
        <tr>
            <td><strong>Total inc VAT</strong></td>
            <td class="right"><strong>{{ number_format((float) $version->total_inc_vat, 2, ',', ' ') }}</strong></td>
        </tr>
    </table>
</body>
</html>
