@extends('customerportal::layouts.portal')

@section('title', $version->quote->quote_key.' v'.$version->version_number)

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Quote Detail -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $version->title }}</h1>
            <div class="small text-muted">{{ $version->quote->quote_key }} v{{ $version->version_number }} &middot; {{ $context->client->name }}</div>
        </div>
        <a href="{{ route('customer-portal.quotes.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
            Back
        </a>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-body d-flex align-items-center justify-content-between gap-2">
                    <h2 class="h6 mb-0">Quote details</h2>
                    <span class="badge text-bg-light border">{{ $access->statusLabel($version) }}</span>
                </div>
                <div class="card-body">
                    @foreach(['intro_text', 'scope_text', 'assumptions_text', 'exclusions_text', 'next_steps_text'] as $field)
                        @if(filled($version->{$field}))
                            <div class="{{ $loop->first ? '' : 'mt-3' }}">
                                <h3 class="h6">{{ ucwords(str_replace('_', ' ', str_replace('_text', '', $field))) }}</h3>
                                <p class="mb-0 small" style="white-space: pre-wrap;">{{ $version->{$field} }}</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="card shadow-sm mt-3">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Quote lines</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Description</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Unit ex. VAT</th>
                                <th class="text-end">Total ex. VAT</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($version->lines as $line)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $line->name }}</div>
                                        @if(filled($line->description))
                                            <div class="text-muted small">{{ $line->description }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ number_format((float) $line->quantity, 2, ',', ' ') }}</td>
                                    <td class="text-end">{{ number_format((float) $line->unit_price_ex_vat, 2, ',', ' ') }}</td>
                                    <td class="text-end fw-semibold">{{ number_format((float) $line->line_total_ex_vat, 2, ',', ' ') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No quote lines are visible.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Totals</h2>
                </div>
                <div class="card-body">
                    <dl class="row small mb-0">
                        <dt class="col-7 text-muted">Subtotal ex. VAT</dt>
                        <dd class="col-5 text-end">{{ number_format((float) $version->total_ex_vat, 2, ',', ' ') }} kr</dd>

                        <dt class="col-7 text-muted">VAT</dt>
                        <dd class="col-5 text-end">{{ number_format((float) $version->vat_total, 2, ',', ' ') }} kr</dd>

                        <dt class="col-7 text-muted">Total inc. VAT</dt>
                        <dd class="col-5 text-end fw-semibold">{{ number_format((float) $version->total_inc_vat, 2, ',', ' ') }} kr</dd>

                        <dt class="col-7 text-muted">Expires</dt>
                        <dd class="col-5 text-end">{{ $version->expires_at?->format('Y-m-d') ?: '-' }}</dd>
                    </dl>
                </div>
            </div>

            @if($version->status === 'accepted')
                <div class="alert alert-success mt-3">
                    <div class="fw-semibold">Quote accepted</div>
                    <div class="small">Accepted by {{ $version->accepted_by_name }} {{ $version->accepted_at?->format('Y-m-d H:i') }}</div>
                </div>
            @elseif($access->canAccept($version))
                <div class="card shadow-sm mt-3">
                    <div class="card-header bg-body">
                        <h2 class="h6 mb-0">Accept quote</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('customer-portal.quotes.accept', $version) }}">
                            @csrf
                            <label for="quote_accept_name" class="form-label small">Name</label>
                            <input id="quote_accept_name" type="text" name="name" class="form-control form-control-sm mb-2" value="{{ old('name', $context->contact->display_name) }}" required>
                            <div class="form-check mb-3">
                                <input type="checkbox" name="confirm" value="1" id="quote_confirm" class="form-check-input" required>
                                <label for="quote_confirm" class="form-check-label small">I accept this quote.</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
                                Accept quote
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <div class="alert alert-info mt-3">This quote cannot be accepted in its current status.</div>
            @endif

            @if($version->status === 'sent')
                <div class="card shadow-sm mt-3">
                    <div class="card-header bg-body">
                        <h2 class="h6 mb-0">Ask a question</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('customer-portal.quotes.question', $version) }}">
                            @csrf
                            <label for="quote_question_message" class="form-label small">Message</label>
                            <textarea id="quote_question_message" name="message" rows="4" class="form-control form-control-sm mb-3" required>{{ old('message') }}</textarea>
                            <button type="submit" class="btn btn-outline-primary w-100">
                                <i class="bi bi-chat-dots me-1" aria-hidden="true"></i>
                                Send question
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
