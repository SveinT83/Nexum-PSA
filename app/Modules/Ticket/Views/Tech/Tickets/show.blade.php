@extends('layouts.default_tech')

@section('title', $ticket->ticket_key)

@section('pageName')
    <h3>Tickets</h3>
@endsection

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="mb-1">{{ $ticket->ticket_key }}</h1>
            <p class="text-muted mb-0">{{ $ticket->subject }}</p>
        </div>
        <a href="{{ route('tech.tickets.index') }}" class="btn btn-light">Back</a>
    </div>
@endsection

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card mb-3">
                <div class="card-header">Conversation</div>
                <div class="card-body">
                    @forelse ($ticket->messages->sortByDesc('created_at') as $message)
                        <div class="border-bottom pb-3 mb-3">
                            <div class="d-flex justify-content-between">
                                <strong>{{ ucfirst(str_replace('_', ' ', $message->type)) }}</strong>
                                <span class="text-muted small">{{ $message->created_at?->diffForHumans() }}</span>
                            </div>
                            <div class="text-muted small mb-2">{{ ucfirst($message->visibility) }}</div>
                            <div style="white-space: pre-wrap;">{{ $message->body }}</div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">No messages yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="card">
                <div class="card-header">Add message</div>
                <div class="card-body">
                    @php
                        $canReplyToContact = filled($ticket->contact?->email);
                        $defaultMessageType = $canReplyToContact ? 'customer_reply' : 'internal_note';
                        $selectedMessageType = old('type', $defaultMessageType);
                    @endphp

                    <form method="POST" action="{{ route('tech.tickets.messages.store', $ticket) }}">
                        @csrf

                        <input id="visibility" name="visibility" type="hidden" value="{{ $selectedMessageType === 'internal_note' ? 'internal' : 'public' }}">

                        <div class="mb-3">
                            <label for="type" class="form-label">Message type</label>
                            <select id="type" name="type" class="form-select @error('type') is-invalid @enderror">
                                @if ($canReplyToContact)
                                    <option value="customer_reply" @selected($selectedMessageType === 'customer_reply')>Reply to contact</option>
                                @endif
                                <option value="internal_note" @selected($selectedMessageType === 'internal_note')>Internal note</option>
                            </select>
                            @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div id="reply_to_contact" class="alert alert-light border @if ($selectedMessageType === 'internal_note') d-none @endif">
                            @if ($canReplyToContact)
                                <div class="small text-muted">Reply to</div>
                                <strong>{{ $ticket->contact->name }}</strong>
                                <div>{{ $ticket->contact->email }}</div>
                            @endif
                        </div>

                        <div id="internal_note_hint" class="alert alert-secondary @if ($selectedMessageType !== 'internal_note') d-none @endif">
                            Internal notes stay inside tdPSA and are not sent to the customer.
                        </div>

                        @if (! $canReplyToContact)
                            <div class="alert alert-warning">
                                This ticket has no contact with an email address. Only internal notes are available until a contact email is added.
                            </div>
                        @endif

                        <div class="form-group">
                            <label for="body">Message</label>
                            <textarea id="body" name="body" rows="5" class="form-control @error('body') is-invalid @enderror" required>{{ old('body') }}</textarea>
                            @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary">Add message</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const type = document.getElementById('type');
        const visibility = document.getElementById('visibility');
        const replyToContact = document.getElementById('reply_to_contact');
        const internalNoteHint = document.getElementById('internal_note_hint');

        type.addEventListener('change', function () {
            const isInternal = this.value === 'internal_note';

            visibility.value = isInternal ? 'internal' : 'public';
            replyToContact.classList.toggle('d-none', isInternal);
            internalNoteHint.classList.toggle('d-none', ! isInternal);
        });
    });
</script>
@endsection

@section('rightbar')
    <x-card.default title="Details">
        <dl class="mb-0 small">
            <dt>Queue</dt>
            <dd>{{ $ticket->queue?->name }}</dd>
            <dt>Status</dt>
            <dd>{{ $ticket->status?->name }}</dd>
            <dt>Priority</dt>
            <dd>P{{ $ticket->priority?->level }} {{ $ticket->priority?->name }}</dd>
            <dt>Channel</dt>
            <dd>{{ ucfirst($ticket->channel) }}</dd>
            <dt>Created</dt>
            <dd>{{ $ticket->created_at?->format('Y-m-d H:i') }}</dd>
            <dt>Updated</dt>
            <dd class="mb-0">{{ $ticket->updated_at?->format('Y-m-d H:i') }}</dd>
        </dl>
    </x-card.default>

    <x-card.default title="Events">
        @forelse ($ticket->events->sortByDesc('created_at') as $event)
            <div class="mb-3 small">
                <div><strong>{{ ucfirst(str_replace('_', ' ', $event->type)) }}</strong></div>
                <div class="text-muted">{{ $event->created_at?->diffForHumans() }}</div>
                @if ($event->message)
                    <div>{{ $event->message }}</div>
                @endif
            </div>
        @empty
            <p class="text-muted small mb-0">No events yet.</p>
        @endforelse
    </x-card.default>
@endsection
