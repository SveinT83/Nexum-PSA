<!DOCTYPE html>
<html>
<head>
    {{--
        Risk Assessment PDF Report

        This view is rendered by RiskController::exportPdf() through Dompdf.
        Keep CSS simple and self-contained: Dompdf has limited support for
        modern layout features, so tables and conservative block styling are
        preferred over flex/grid for reliable report output.
    --}}
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Risikoanalyse: {{ $risk->title }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11px;
            color: #333;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }
        @page {
            margin: 1.5cm;
        }
        .header {
            border-bottom: 2px solid #FF6D1F;
            margin-bottom: 20px;
            padding-bottom: 10px;
        }
        .header table {
            width: 100%;
        }
        .title {
            font-size: 22px;
            font-weight: bold;
            color: #222;
        }
        .scope {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }

        /* Summary Section */
        .overall-summary {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 25px;
        }
        .summary-grid {
            width: 100%;
        }
        .summary-box {
            padding: 10px;
            text-align: center;
        }
        .summary-value {
            font-size: 20px;
            font-weight: bold;
            display: block;
            color: #FF6D1F;
        }
        .summary-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #666;
        }

        /* Risk Block Styling */
        .risk-block {
            margin-bottom: 30px;
            page-break-inside: avoid;
            border: 1px solid #eee;
            border-radius: 5px;
            overflow: hidden;
        }
        .category-divider {
            background-color: #333;
            color: white;
            padding: 6px 12px;
            font-weight: bold;
            font-size: 12px;
            text-transform: uppercase;
            margin-top: 25px;
            margin-bottom: 15px;
        }
        .risk-header {
            background-color: #fcfcfc;
            border-bottom: 1px solid #eee;
            padding: 10px 15px;
        }
        .risk-title {
            font-size: 16px;
            font-weight: bold;
            margin: 0;
            color: #000;
        }
        .risk-meta {
            margin-top: 5px;
            font-size: 11px;
        }
        .risk-content {
            padding: 15px;
        }
        .section-title {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
            color: #888;
            margin-bottom: 5px;
            margin-top: 15px;
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 2px;
        }
        .section-title:first-child {
            margin-top: 0;
        }

        .recommended-actions {
            background-color: #fff9f5;
            border-left: 4px solid #FF6D1F;
            padding: 10px 15px;
            margin-top: 10px;
        }
        .conclusion-box {
            background-color: #f0fff4;
            border-left: 4px solid #28a745;
            padding: 10px 15px;
            margin-top: 10px;
        }

        .badge {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
            display: inline-block;
        }
        .badge-critical { background-color: #212529; color: white; }
        .badge-high { background-color: #dc3545; color: white; }
        .badge-medium { background-color: #ffc107; color: #212529; }
        .badge-low { background-color: #198754; color: white; }

        .history-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .history-item {
            font-size: 10px;
            color: #666;
            margin-bottom: 4px;
            padding-bottom: 4px;
            border-bottom: 1px dotted #f0f0f0;
        }
        .history-date {
            color: #999;
            margin-right: 10px;
        }

        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td>
                    <div class="title">Risikoanalyse Rapport</div>
                    <div class="scope">
                        Prosjekt: {{ $risk->title }}<br>
                        Scope: {{ $risk->client_id ? 'Klient (' . ($risk->client->name ?? 'Ukjent') . ')' : 'Intern' }}
                    </div>
                </td>
                <td style="text-align: right; vertical-align: bottom;">
                    <div style="font-size: 12px; color: #666;">
                        Generert: {{ now()->format('d.m.Y') }}<br>
                        Status: <strong>{{ ucfirst($risk->status) }}</strong>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="overall-summary">
        <table class="summary-grid">
            <tr>
                <td class="summary-box" style="width: 25%; border-right: 1px solid #dee2e6;">
                    <span class="summary-label">Total Risikonivå</span>
                    <span class="summary-value">{{ $summary['level'] }}</span>
                </td>
                <td class="summary-box" style="width: 25%; border-right: 1px solid #dee2e6;">
                    <span class="summary-label">Identifiserte Risikoer</span>
                    <span class="summary-value">{{ $summary['total'] }}</span>
                </td>
                <td class="summary-box" style="width: 25%; border-right: 1px solid #dee2e6;">
                    <span class="summary-label">Håndtert (Mitigated)</span>
                    <span class="summary-value">{{ $summary['mitigated'] }}</span>
                </td>
                <td class="summary-box" style="width: 25%;">
                    <span class="summary-label">Åpne Risikoer</span>
                    <span class="summary-value" style="color: {{ $summary['open'] > 0 ? '#dc3545' : '#198754' }}">{{ $summary['open'] }}</span>
                </td>
            </tr>
        </table>

        @if(!empty($summary['critical_areas']))
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #dee2e6; font-size: 10px;">
                <strong>Kritiske Områder:</strong> {{ implode(', ', $summary['critical_areas']) }}
            </div>
        @endif
    </div>

    @forelse($groupedItems as $categoryName => $items)
        <div class="category-divider">Kategori: {{ $categoryName }}</div>

        @foreach($items as $item)
            @php
                $level = 'Low';
                $badgeClass = 'badge-low';
                if ($item->score >= 16) { $level = 'Critical'; $badgeClass = 'badge-critical'; }
                elseif ($item->score >= 10) { $level = 'High'; $badgeClass = 'badge-high'; }
                elseif ($item->score >= 5) { $level = 'Medium'; $badgeClass = 'badge-medium'; }

                $statusColor = match($item->status) {
                    'open' => '#dc3545',
                    'mitigated' => '#198754',
                    'accepted' => '#0dcaf0',
                    default => '#6c757d'
                };
            @endphp

            <div class="risk-block">
                <div class="risk-header clearfix">
                    <div style="float: left; width: 70%;">
                        <h4 class="risk-title">{{ $item->title }}</h4>
                        <div class="risk-meta">
                            Status: <span style="color: {{ $statusColor }}; font-weight: bold;">{{ ucfirst($item->status) }}</span>
                        </div>
                    </div>
                    <div style="float: right; width: 25%; text-align: right;">
                        <span class="badge {{ $badgeClass }}" style="padding: 4px 8px; font-size: 11px;">
                            Score: {{ $item->score }} ({{ $level }})
                        </span>
                    </div>
                </div>

                <div class="risk-content">
                    @if($item->description)
                        <div class="section-title">Beskrivelse</div>
                        <div style="margin-bottom: 10px;">{!! nl2br(e($item->description)) !!}</div>
                    @endif

                    @if($item->recommended_actions)
                        <div class="section-title">Anbefalte Tiltak</div>
                        <div class="recommended-actions">
                            {!! nl2br(e($item->recommended_actions)) !!}
                        </div>
                    @endif

                    @if($item->conclusion)
                        <div class="section-title">Konklusjon</div>
                        <div class="conclusion-box">
                            {!! nl2br(e($item->conclusion)) !!}
                        </div>
                    @endif

                    @php
                        $relevantUpdates = $item->updates->filter(function($update) {
                            return in_array($update->status, ['mitigated', 'accepted']);
                        })->sortByDesc('created_at');
                    @endphp

                    @if($relevantUpdates->isNotEmpty())
                        <div class="section-title">Historikk / Utførte Tiltak</div>
                        <ul class="history-list">
                            @foreach($relevantUpdates as $update)
                                <li class="history-item">
                                    <span class="history-date">{{ $update->created_at->format('d.m.Y') }}</span>
                                    <strong>{{ ucfirst($update->status) }}:</strong> {{ $update->note }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endforeach
    @empty
        <div style="text-align: center; padding: 40px; border: 1px dashed #ccc; color: #999;">
            Ingen risikoer er registrert i denne analysen.
        </div>
    @endforelse

    <div style="margin-top: 40px; text-align: center; font-size: 9px; color: #999; border-top: 1px solid #eee; padding-top: 10px;">
        Denne rapporten er generert automatisk av Nexum PSA Risiko Modul.<br>
        &copy; {{ date('Y') }} {{ config('app.name') }}
    </div>
</body>
</html>
