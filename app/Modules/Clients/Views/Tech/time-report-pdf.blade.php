<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Internal Client time report</title>
    <style>
        @page { margin: 18mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
        h1 { font-size: 15px; margin-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 12px; }
        th, td { padding: 4px; border-bottom: 1px solid #ddd; vertical-align: top; word-wrap: break-word; }
        th { text-align: left; background: #eee; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .text-end { text-align: right; }
        .text-muted { color: #666; }
        .card-header { font-weight: bold; font-size: 11px; margin: 12px 0 4px; }
        .alert { margin-bottom: 8px; }
        .row .col { display: inline-block; width: 19%; vertical-align: top; margin-bottom: 8px; }
        .small { font-size: 9px; }
        .report-entry { page-break-inside: avoid; border-bottom: 1px solid #ddd; padding: 6px 0; }
        .entry-label { page-break-after: avoid; }
        .entry-description { white-space: pre-line; word-wrap: break-word; margin: 3px 0; }
    </style>
</head>
<body>
    {{-- Standalone offline PDF; remote resources are disabled --}}
    <h1>Internal Client time report</h1>
    <p>Contains employee names and internal descriptions. Review before sharing.</p>
    @include('clients::Tech.partials.time-report-data', ['pdfMode' => true])
</body>
</html>
