@extends('layouts.default_tech')

@section('title', $ticket->ticket_key)

@section('pageName')
    <h3>Tickets</h3>
@endsection

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h1 class="mb-0">{{ $ticket->ticket_key }}</h1>
                @if ($ticket->is_unread)
                    <span class="badge text-bg-primary">Unread</span>
                @endif
            </div>
            <p class="text-muted mb-0">{{ $ticket->subject }}</p>
        </div>
        <div class="d-flex gap-2">
            @if ($ticket->is_unread)
                <!-- Marks the ticket and its current messages as read without changing ticket status or ownership. -->
                <form method="POST" action="{{ route('tech.tickets.read', $ticket) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary">Mark as read</button>
                </form>
            @endif
            @if (! $ticket->status?->is_closed)
                <!-- Convenience close action uses the same lifecycle action as manual status changes. -->
                <form method="POST" action="{{ route('tech.tickets.close', $ticket) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary">Close</button>
                </form>
            @endif
            <a href="{{ route('tech.tickets.index') }}" class="btn btn-light">Back</a>
        </div>
    </div>
@endsection

@section('content')
<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="row">
        <div class="col-12">
            <div class="card mb-3">
                <div class="card-header">Conversation</div>
                <div class="card-body">
                    @forelse ($ticket->messages->sortByDesc('created_at') as $message)
                        @php
                            $latestEmailLog = $emailLogsByMessageId->get($message->id)?->first();
                        @endphp
                        <div class="border-bottom pb-3 mb-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <strong>{{ ucfirst(str_replace('_', ' ', $message->type)) }}</strong>
                                    @if ($message->type === 'customer_reply')
                                        @if ($latestEmailLog)
                                            <span class="badge {{ $latestEmailLog->level === 'error' ? 'bg-danger' : 'bg-success' }} ms-2">
                                                {{ $latestEmailLog->level === 'error' ? 'Email failed' : 'Email sent' }}
                                            </span>
                                        @else
                                            <span class="badge bg-secondary ms-2">Email queued</span>
                                        @endif
                                    @endif
                                </div>
                                <span class="text-muted small">{{ $message->created_at?->diffForHumans() }}</span>
                            </div>
                            <div class="text-muted small mb-2">{{ ucfirst($message->visibility) }}</div>
                            @if ($message->type === 'customer_reply' && $latestEmailLog)
                                <!-- Shows the latest outbound email status for this ticket message so technicians can see delivery problems without opening email logs. -->
                                <div class="small {{ $latestEmailLog->level === 'error' ? 'text-danger' : 'text-success' }} mb-2">
                                    {{ $latestEmailLog->message }}
                                    @if ($latestEmailLog->rfc_message_id)
                                        <span class="text-muted">({{ $latestEmailLog->rfc_message_id }})</span>
                                    @endif
                                </div>
                            @endif
                            <div style="white-space: pre-wrap;">{{ $message->body }}</div>
                            @if($message->fileAttachments->isNotEmpty())
                                <!-- Attachments are stored as ticket-owned records even when they originated from inbound email. -->
                                <div class="mt-3">
                                    @foreach($message->fileAttachments as $attachment)
                                        <a class="btn btn-sm btn-outline-secondary me-2 mb-2" href="{{ route('tech.tickets.attachments.download', [$ticket, $attachment]) }}">
                                            {{ $attachment->filename }}
                                            @if($attachment->size_bytes)
                                                <span class="text-muted">({{ number_format($attachment->size_bytes / 1024, 1) }} KB)</span>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            @endif
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

                    <form method="POST" action="{{ route('tech.tickets.messages.store', $ticket) }}" enctype="multipart/form-data">
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
                        <div class="form-group mt-3">
                            <label for="attachments" class="form-label">Attachments</label>
                            <input id="attachments" name="attachments[]" type="file" class="form-control @error('attachments') is-invalid @enderror @error('attachments.*') is-invalid @enderror" multiple>
                            <div class="form-text">Up to 5 files, 20 MB each. Customer reply attachments are sent with the outbound email.</div>
                            @error('attachments')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @error('attachments.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
            <dt>Category</dt>
            <dd>{{ $ticket->category?->name ?? '-' }}</dd>
            <dt>Tags</dt>
            <dd>
                @forelse($ticket->tags as $tag)
                    <span class="badge text-bg-light border">{{ $tag->name }}</span>
                @empty
                    -
                @endforelse
            </dd>
            <dt>Owner</dt>
            <dd>{{ $ticket->owner?->name ?? 'Unassigned' }}</dd>
            <dt>Site</dt>
            <dd>{{ $ticket->site?->name ?? '-' }}</dd>
            <dt>Asset</dt>
            <dd>{{ $ticket->asset?->name ?? '-' }}</dd>
            <dt>Channel</dt>
            <dd>{{ ucfirst($ticket->channel) }}</dd>
            <dt>Resolved</dt>
            <dd>{{ $ticket->resolved_at?->format('Y-m-d H:i') ?? '-' }}</dd>
            <dt>Closed</dt>
            <dd>{{ $ticket->closed_at?->format('Y-m-d H:i') ?? '-' }}</dd>
            <dt>Created</dt>
            <dd>{{ $ticket->created_at?->format('Y-m-d H:i') }}</dd>
            <dt>Updated</dt>
            <dd class="mb-0">{{ $ticket->updated_at?->format('Y-m-d H:i') }}</dd>
        </dl>

        <div class="mt-3">
            <a href="{{ route('tech.tickets.edit', $ticket) }}" class="btn btn-sm btn-outline-primary w-100">Edit ticket</a>
        </div>
        <form method="POST" action="{{ route('tech.tickets.assign', $ticket) }}" class="mt-2">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Run assignment</button>
        </form>
    </x-card.default>

    <x-card.default title="Assignment">
        @if($latestAssignmentEvent)
            <div class="small">
                <div class="fw-semibold">{{ $latestAssignmentEvent->message }}</div>
                <div class="text-muted">{{ $latestAssignmentEvent->created_at?->diffForHumans() }}</div>
                @if(isset($latestAssignmentEvent->after['assignment_rule_id']))
                    <div>Rule ID: {{ $latestAssignmentEvent->after['assignment_rule_id'] }}</div>
                @endif
                @if(isset($latestAssignmentEvent->after['score']))
                    <div>Score: {{ $latestAssignmentEvent->after['score'] }}</div>
                @endif
                @if(isset($latestAssignmentEvent->after['open_tickets']))
                    <div>Open tickets: {{ $latestAssignmentEvent->after['open_tickets'] }}</div>
                @endif
            </div>
        @else
            <p class="text-muted small mb-0">No assignment decision has been logged yet.</p>
        @endif
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
