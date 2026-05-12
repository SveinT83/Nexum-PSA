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
    <div class="row">
        <div class="col-12">
            <!-- -------------------------------------------------------------------------------------------------- -->
            <!-- Ticket lifecycle fields -->
            <!-- Basic ticket operations live here until Workflow validation is introduced. -->
            <!-- -------------------------------------------------------------------------------------------------- -->
            <div class="card mb-3">
                <div class="card-header">Ticket lifecycle</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('tech.tickets.update', $ticket) }}">
                        @csrf
                        @method('PATCH')

                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="status_id" class="form-label">Status</label>
                                <select id="status_id" name="status_id" class="form-select @error('status_id') is-invalid @enderror">
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status->id }}" @selected(old('status_id', $ticket->status_id) == $status->id)>{{ $status->name }}</option>
                                    @endforeach
                                </select>
                                @error('status_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-3">
                                <label for="queue_id" class="form-label">Queue</label>
                                <select id="queue_id" name="queue_id" class="form-select @error('queue_id') is-invalid @enderror">
                                    @foreach ($queues as $queue)
                                        <option value="{{ $queue->id }}" @selected(old('queue_id', $ticket->queue_id) == $queue->id)>{{ $queue->name }}</option>
                                    @endforeach
                                </select>
                                @error('queue_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-3">
                                <label for="priority_id" class="form-label">Priority</label>
                                <select id="priority_id" name="priority_id" class="form-select @error('priority_id') is-invalid @enderror">
                                    @foreach ($priorities as $priority)
                                        <option value="{{ $priority->id }}" @selected(old('priority_id', $ticket->priority_id) == $priority->id)>P{{ $priority->level }} {{ $priority->name }}</option>
                                    @endforeach
                                </select>
                                @error('priority_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-3">
                                <label for="owner_id" class="form-label">Owner</label>
                                <select id="owner_id" name="owner_id" class="form-select @error('owner_id') is-invalid @enderror">
                                    <option value="">Unassigned</option>
                                    @foreach ($technicians as $technician)
                                        <option value="{{ $technician->id }}" @selected(old('owner_id', $ticket->owner_id) == $technician->id)>{{ $technician->name }}</option>
                                    @endforeach
                                </select>
                                @error('owner_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-3">
                                <label for="category_id" class="form-label">Category</label>
                                <select id="category_id" name="category_id" class="form-select @error('category_id') is-invalid @enderror">
                                    <option value="">No category</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}" @selected(old('category_id', $ticket->category_id) == $category->id)>{{ $category->name }}</option>
                                    @endforeach
                                </select>
                                @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">Save ticket fields</button>
                        </div>
                    </form>
                </div>
            </div>

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
            <dt>Owner</dt>
            <dd>{{ $ticket->owner?->name ?? 'Unassigned' }}</dd>
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
