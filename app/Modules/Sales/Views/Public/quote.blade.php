<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $version->quote->quote_key }} - Quote</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-4">
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-warning">{{ session('error') }}</div>
    @endif

    <div class="bg-white border rounded p-4 mb-4">
        <div class="d-flex justify-content-between gap-3">
            <div>
                <h1 class="mb-1">{{ $version->title }}</h1>
                <div class="text-muted">{{ $version->quote->quote_key }} v{{ $version->version_number }}</div>
            </div>
            <div class="text-end">
                <div class="fw-semibold">{{ $opportunity->client?->name }}</div>
                <div class="text-muted small">Expires {{ $version->expires_at?->format('d.m.Y') ?: 'not set' }}</div>
                <a href="{{ route('sales.quotes.public.pdf', $version->secure_token) }}" class="btn btn-sm btn-outline-primary mt-2">Download PDF</a>
            </div>
        </div>

        @foreach(['intro_text', 'scope_text', 'assumptions_text', 'exclusions_text', 'next_steps_text'] as $field)
            @if(filled($version->{$field}))
                <div class="mt-4">
                    <h2 class="h5">{{ ucwords(str_replace('_', ' ', str_replace('_text', '', $field))) }}</h2>
                    <p class="mb-0">{!! nl2br(e($version->{$field})) !!}</p>
                </div>
            @endif
        @endforeach
    </div>

    <div class="bg-white border rounded p-4 mb-4">
        <h2 class="h5">Quote Lines</h2>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Unit ex VAT</th>
                    <th class="text-end">Total ex VAT</th>
                </tr>
                </thead>
                <tbody>
                @foreach($version->lines as $line)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $line->name }}</div>
                            <div class="text-muted small">{{ $line->description }}</div>
                        </td>
                        <td class="text-end">{{ $line->quantity }}</td>
                        <td class="text-end">{{ number_format((float) $line->unit_price_ex_vat, 2, ',', ' ') }}</td>
                        <td class="text-end">{{ number_format((float) $line->line_total_ex_vat, 2, ',', ' ') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-end">
            <dl class="row mb-0 text-end" style="min-width: 22rem;">
                <dt class="col-7">Subtotal ex VAT</dt>
                <dd class="col-5">{{ number_format((float) $version->total_ex_vat, 2, ',', ' ') }}</dd>
                <dt class="col-7">VAT</dt>
                <dd class="col-5">{{ number_format((float) $version->vat_total, 2, ',', ' ') }}</dd>
                <dt class="col-7">Total inc VAT</dt>
                <dd class="col-5 fw-semibold">{{ number_format((float) $version->total_inc_vat, 2, ',', ' ') }}</dd>
            </dl>
        </div>
    </div>

    @if($version->status === 'sent')
        <div class="row g-4">
            <div class="col-md-6">
                <div class="bg-white border rounded p-4 h-100">
                    <h2 class="h5">Accept Quote</h2>
                    <form method="POST" action="{{ route('sales.quotes.public.accept', $version->secure_token) }}">
                        @csrf
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control mb-3" required>
                        <div class="form-check mb-3">
                            <input type="checkbox" name="confirm" value="1" id="confirm" class="form-check-input" required>
                            <label for="confirm" class="form-check-label">I accept this quote.</label>
                        </div>
                        <button type="submit" class="btn btn-success">Accept Quote</button>
                    </form>
                </div>
            </div>
            <div class="col-md-6">
                <div class="bg-white border rounded p-4 h-100">
                    <h2 class="h5">Ask A Question</h2>
                    <form method="POST" action="{{ route('sales.quotes.public.question', $version->secure_token) }}">
                        @csrf
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control mb-2" required>
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control mb-2">
                        <label class="form-label">Message</label>
                        <textarea name="message" rows="4" class="form-control mb-3" required></textarea>
                        <button type="submit" class="btn btn-outline-primary">Send Question</button>
                    </form>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-info">This quote is {{ $version->status }}.</div>
    @endif
</main>
</body>
</html>
