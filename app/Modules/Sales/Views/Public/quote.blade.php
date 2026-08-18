<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $version->quote->quote_key }} - Quote</title>
    @PwaHead
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
    @if($errors->any())
        <div class="alert alert-warning">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
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

        @foreach($quotePresentation['before_copy'] as $section)
            <div class="mt-4">
                <h2 class="h5">{{ $section['label'] }}</h2>
                <p class="mb-0">{!! nl2br(e($section['text'])) !!}</p>
            </div>
        @endforeach
    </div>

    <div class="bg-white border rounded p-4 mb-4">
        <h2 class="h5">Quote Lines</h2>
        @include('sales::Partials.quote-groups', [
            'quotePresentation' => $quotePresentation,
            'selectionFormId' => $version->status === 'sent' ? 'publicQuoteAcceptForm' : null,
        ])

        @foreach($quotePresentation['after_copy'] as $section)
            <div class="mt-4">
                <h2 class="h5">{{ $section['label'] }}</h2>
                <p class="mb-0">{!! nl2br(e($section['text'])) !!}</p>
            </div>
        @endforeach
    </div>

    @if($version->status === 'sent')
        <div class="row g-4">
            <div class="col-md-6">
                <div class="bg-white border rounded p-4 h-100">
                    <h2 class="h5">Accept Quote</h2>
                    <form method="POST" action="{{ route('sales.quotes.public.accept', $version->secure_token) }}" id="publicQuoteAcceptForm">
                        @csrf
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control mb-3" required>
                        <label class="form-label">Email <span class="text-muted">(optional)</span></label>
                        <input type="email" name="email" class="form-control mb-3">
                        @if($quotePresentation['acknowledgements']->isNotEmpty())
                            <div class="border rounded p-3 mb-3">
                                <div class="fw-semibold mb-2">Important information</div>
                                @foreach($quotePresentation['acknowledgements'] as $acknowledgement)
                                    @php
                                        $ackLine = $acknowledgement->quote_line_id ? $version->lines->firstWhere('id', $acknowledgement->quote_line_id) : null;
                                        $mustCheck = $acknowledgement->is_required && ($acknowledgement->quote_line_id === null || $ackLine?->is_required);
                                    @endphp
                                    <div class="form-check mb-2">
                                        <input
                                            type="checkbox"
                                            name="acknowledgement_ids[]"
                                            value="{{ $acknowledgement->id }}"
                                            id="acknowledgement_{{ $acknowledgement->id }}"
                                            class="form-check-input"
                                            @required($mustCheck)
                                        >
                                        <label for="acknowledgement_{{ $acknowledgement->id }}" class="form-check-label">
                                            <span class="fw-semibold">{{ $acknowledgement->title }}</span>
                                            <span class="d-block small text-muted" style="white-space: pre-wrap;">{{ $acknowledgement->body }}</span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @endif
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
                    <hr>
                    <h3 class="h6">Decline Quote</h3>
                    <form method="POST" action="{{ route('sales.quotes.public.decline', $version->secure_token) }}">
                        @csrf
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control mb-2" required>
                        <label class="form-label">Reason <span class="text-muted">(optional)</span></label>
                        <textarea name="reason" rows="3" class="form-control mb-3"></textarea>
                        <button type="submit" class="btn btn-outline-danger">Decline Quote</button>
                    </form>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-info">This quote is {{ $version->status }}.</div>
    @endif
</main>
@RegisterServiceWorkerScript
</body>
</html>
