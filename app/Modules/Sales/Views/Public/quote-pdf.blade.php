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

    @foreach($quotePresentation['before_copy'] as $section)
        <h2>{{ $section['label'] }}</h2>
        <p>{!! nl2br(e($section['text'])) !!}</p>
    @endforeach

    <h2>Quote Lines</h2>
    @include('sales::Partials.quote-groups', ['quotePresentation' => $quotePresentation])

    @foreach($quotePresentation['after_copy'] as $section)
        <h2>{{ $section['label'] }}</h2>
        <p>{!! nl2br(e($section['text'])) !!}</p>
    @endforeach
</body>
</html>
