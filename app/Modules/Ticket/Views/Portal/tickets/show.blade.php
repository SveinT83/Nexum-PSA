@extends('customerportal::layouts.portal')

@section('title', $ticket->ticket_key)

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Ticket Detail -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h1 class="h4 mb-0">{{ $ticket->ticket_key }}</h1>
                <span class="badge text-bg-light border">{{ $access->publicStatusLabel($ticket) }}</span>
            </div>
            <div class="text-muted">{{ $ticket->subject }}</div>
            <div class="small text-muted">{{ $ticket->client?->name }}{{ $ticket->site ? ' - '.$ticket->site->name : '' }}</div>
        </div>
        <a href="{{ route('customer-portal.tickets.index') }}" class="btn btn-outline-secondary">Back</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Conversation</h2>
                </div>
                <div class="list-group list-group-flush">
                    @forelse($messages as $message)
                        <div class="list-group-item">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                <div class="fw-semibold">
                                    {{ $message->author_type === 'portal_user' ? ($context->contact->display_name ?: 'Customer') : ($message->author?->name ?: 'Support') }}
                                </div>
                                <div class="small text-muted">{{ $message->created_at?->format('Y-m-d H:i') }}</div>
                            </div>
                            <div style="white-space: pre-wrap;">{{ $message->body }}</div>

                            @if($message->fileAttachments->isNotEmpty())
                                <div class="mt-3 d-flex flex-wrap gap-2">
                                    @foreach($message->fileAttachments as $attachment)
                                        <a href="{{ route('customer-portal.tickets.attachments.download', [$ticket, $attachment]) }}" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-paperclip me-1" aria-hidden="true"></i>
                                            {{ $attachment->filename }}
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="list-group-item text-muted">No public conversation has been shared yet.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Reply</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('customer-portal.tickets.messages.store', $ticket) }}">
                        @csrf
                        <div class="mb-3">
                            <label for="body" class="form-label">Message</label>
                            <textarea id="body" name="body" rows="7" class="form-control @error('body') is-invalid @enderror" required>{{ old('body') }}</textarea>
                            @error('body') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-reply me-1" aria-hidden="true"></i>
                                Send reply
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
