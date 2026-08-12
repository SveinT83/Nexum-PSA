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
                    @foreach($quotePresentation['before_copy'] as $section)
                        <div class="{{ $loop->first ? '' : 'mt-3' }}">
                            <h3 class="h6">{{ $section['label'] }}</h3>
                            <p class="mb-0 small" style="white-space: pre-wrap;">{{ $section['text'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card shadow-sm mt-3">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Quote lines</h2>
                </div>
                <div class="card-body">
                    @include('sales::Partials.quote-groups', [
                        'quotePresentation' => $quotePresentation,
                        'selectionFormId' => $access->canAccept($version) ? 'portalQuoteAcceptForm' : null,
                    ])

                    @foreach($quotePresentation['after_copy'] as $section)
                        <div class="mt-3">
                            <h3 class="h6">{{ $section['label'] }}</h3>
                            <p class="mb-0 small" style="white-space: pre-wrap;">{{ $section['text'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Quote status</h2>
                </div>
                <div class="card-body">
                    <dl class="row small mb-0">
                        <dt class="col-7 text-muted">Expires</dt>
                        <dd class="col-5 text-end">{{ $version->expires_at?->format('Y-m-d') ?: '-' }}</dd>
                        <dt class="col-7 text-muted">Status</dt>
                        <dd class="col-5 text-end">{{ $access->statusLabel($version) }}</dd>
                    </dl>
                </div>
            </div>

            @if($version->status === 'accepted')
                <div class="alert alert-success mt-3">
                    <div class="fw-semibold">Quote accepted</div>
                    <div class="small">Accepted by {{ $version->accepted_by_name }} {{ $version->accepted_at?->format('Y-m-d H:i') }}</div>
                </div>
            @elseif($version->status === 'declined')
                <div class="alert alert-warning mt-3">
                    <div class="fw-semibold">Quote declined</div>
                    <div class="small">Declined by {{ $version->declined_by_name }} {{ $version->declined_at?->format('Y-m-d H:i') }}</div>
                </div>
            @elseif($access->canAccept($version))
                <div class="card shadow-sm mt-3">
                    <div class="card-header bg-body">
                        <h2 class="h6 mb-0">Accept quote</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('customer-portal.quotes.accept', $version) }}" id="portalQuoteAcceptForm">
                            @csrf
                            <label for="quote_accept_name" class="form-label small">Name</label>
                            <input id="quote_accept_name" type="text" name="name" class="form-control form-control-sm mb-2" value="{{ old('name', $context->contact->display_name) }}" required>
                            @if($quotePresentation['acknowledgements']->isNotEmpty())
                                <div class="border rounded p-2 mb-3">
                                    <div class="fw-semibold small mb-2">Important information</div>
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
                                                id="portal_acknowledgement_{{ $acknowledgement->id }}"
                                                class="form-check-input"
                                                @required($mustCheck)
                                            >
                                            <label for="portal_acknowledgement_{{ $acknowledgement->id }}" class="form-check-label small">
                                                <span class="fw-semibold">{{ $acknowledgement->title }}</span>
                                                <span class="d-block text-muted" style="white-space: pre-wrap;">{{ $acknowledgement->body }}</span>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
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
                <div class="card shadow-sm mt-3">
                    <div class="card-header bg-body">
                        <h2 class="h6 mb-0">Decline quote</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('customer-portal.quotes.decline', $version) }}">
                            @csrf
                            <label for="quote_decline_name" class="form-label small">Name</label>
                            <input id="quote_decline_name" type="text" name="name" class="form-control form-control-sm mb-2" value="{{ old('name', $context->contact->display_name) }}" required>
                            <label for="quote_decline_reason" class="form-label small">Reason <span class="text-muted">(optional)</span></label>
                            <textarea id="quote_decline_reason" name="reason" rows="3" class="form-control form-control-sm mb-3">{{ old('reason') }}</textarea>
                            <button type="submit" class="btn btn-outline-danger w-100">
                                <i class="bi bi-x-circle me-1" aria-hidden="true"></i>
                                Decline quote
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
