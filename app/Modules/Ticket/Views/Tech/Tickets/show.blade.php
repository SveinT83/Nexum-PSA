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

    @php
        $canReplyToContact = filled($ticket->contact?->email);
        $defaultMessageType = $canReplyToContact ? 'customer_reply' : 'internal_note';
        $selectedMessageType = old('type', $defaultMessageType);
        $showAddMessage = $errors->any() || old('body') || old('type');
    @endphp

    <div class="row">
        <div class="col-12">
            {{-- Original request summary --}}
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between gap-3 py-2">
                    <h2 class="h6 mb-0">{{ $ticket->subject }}</h2>
                    <x-buttons.editlink
                        url="{{ route('tech.tickets.edit', $ticket) }}"
                        class="btn btn-sm btn-outline-primary bi bi-pencil">
                        Edit ticket
                    </x-buttons.editlink>
                </div>
                <div class="card-body">
                    @if(filled($ticket->description))
                        <!-- The ticket description is the original request text and should stay above the conversation timeline. -->
                        <div style="white-space: pre-wrap;">{{ $ticket->description }}</div>
                    @else
                        <p class="text-muted mb-0">No initial description provided.</p>
                    @endif
                </div>
                <div class="card-footer d-flex align-items-center gap-2 py-2">
                    <!-- Quick actions keep the original request card useful without moving the composer into view by default. -->
                    <button
                        id="ticketReplyShortcut"
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        data-message-type="{{ $canReplyToContact ? 'customer_reply' : 'internal_note' }}">
                        <i class="bi bi-reply" aria-hidden="true"></i>
                        Reply
                    </button>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Conversation</div>
                <div class="card-body">
                    @if($ticket->messages->isNotEmpty())
                        <div class="accordion accordion-flush" id="ticketConversationAccordion">
                            @foreach ($ticket->messages->sortByDesc('created_at') as $message)
                                @php
                                    $latestEmailLog = $emailLogsByMessageId->get($message->id)?->first();
                                    // Only customer/contact authored messages are unread workflow items for technicians.
                                    $isUnreadMessage = $message->author_type === 'contact' && blank($message->read_at);
                                    $messageTypeLabel = $message->type === 'customer_reply' && $message->author_type === 'user'
                                        ? 'Technician reply'
                                        : ucfirst(str_replace('_', ' ', $message->type));
                                    $messageExcerpt = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', trim($message->body)), 120);
                                    $senderName = $message->author_type === 'contact'
                                        ? (iconv_mime_decode((string) ($message->metadata['from_name'] ?? ''), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $ticket->contact?->name ?? 'Customer')
                                        : ($message->author?->name ?? 'Technician');
                                    $senderEmail = $message->author_type === 'contact'
                                        ? ($message->metadata['from_email'] ?? $ticket->contact?->email)
                                        : null;
                                    $participantLine = $message->author_type === 'contact'
                                        ? ($senderEmail ?: $senderName)
                                        : $senderName;
                                    $messageCollapseId = 'ticketMessageCollapse' . $message->id;
                                    $messageHeadingId = 'ticketMessageHeading' . $message->id;
                                @endphp
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="{{ $messageHeadingId }}">
                                        <button
                                            class="accordion-button py-2 px-0 {{ $isUnreadMessage ? '' : 'collapsed' }}"
                                            type="button"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#{{ $messageCollapseId }}"
                                            aria-expanded="{{ $isUnreadMessage ? 'true' : 'false' }}"
                                            aria-controls="{{ $messageCollapseId }}">
                                            <span class="d-flex align-items-center gap-2 w-100 pe-3 text-start min-w-0">
                                                <span class="fw-semibold flex-shrink-0">{{ $messageTypeLabel }}</span>
                                                @if ($isUnreadMessage)
                                                    <span class="text-primary small fw-semibold flex-shrink-0">Unread</span>
                                                @endif
                                                <span class="small text-muted text-truncate flex-shrink-0" style="max-width: 14rem;">{{ $participantLine }}</span>
                                                <span class="small text-body text-truncate min-w-0 flex-grow-1">
                                                    {{ $messageExcerpt !== '' ? $messageExcerpt : 'No message text.' }}
                                                </span>
                                                <span class="text-muted small flex-shrink-0">{{ $message->created_at?->diffForHumans() }}</span>
                                            </span>
                                        </button>
                                    </h2>
                                    <div
                                        id="{{ $messageCollapseId }}"
                                        class="accordion-collapse collapse {{ $isUnreadMessage ? 'show' : '' }}"
                                        aria-labelledby="{{ $messageHeadingId }}">
                                        <div class="accordion-body px-0 pt-2 pb-3">
                                            @if ($isUnreadMessage)
                                                <!-- Per-message read handling lets technicians clear one reply while leaving other unread replies open. -->
                                                <div class="d-flex justify-content-end mb-2">
                                                    <form method="POST" action="{{ route('tech.tickets.messages.read', [$ticket, $message]) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Mark as read</button>
                                                    </form>
                                                </div>
                                            @endif
                                            @if ($message->type === 'customer_reply' && $latestEmailLog)
                                                <!-- Shows the latest outbound email status for this ticket message so technicians can see delivery problems without opening email logs. -->
                                                <div class="small {{ $latestEmailLog->level === 'error' ? 'text-danger' : 'text-success' }} mb-2">
                                                    {{ $latestEmailLog->level === 'error' ? 'Email failed' : 'Email sent' }}:
                                                    {{ $latestEmailLog->message }}
                                                    @if ($latestEmailLog->rfc_message_id)
                                                        <span class="text-muted">({{ $latestEmailLog->rfc_message_id }})</span>
                                                    @endif
                                                </div>
                                            @elseif ($message->type === 'customer_reply' && $message->author_type === 'user')
                                                <div class="small text-muted mb-2">Email queued or waiting for delivery log.</div>
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
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-muted mb-0">No messages yet.</p>
                    @endif
                </div>
            </div>

            {{-- Add message composer --}}
            <div class="accordion" id="ticketComposerAccordion">
                <div class="accordion-item border rounded overflow-hidden">
                    <h2 class="accordion-header" id="ticketComposerHeading">
                        <button
                            class="accordion-button py-2 {{ $showAddMessage ? '' : 'collapsed' }}"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#ticketComposerCollapse"
                            aria-expanded="{{ $showAddMessage ? 'true' : 'false' }}"
                            aria-controls="ticketComposerCollapse">
                            Add message
                        </button>
                    </h2>
                    <div
                        id="ticketComposerCollapse"
                        class="accordion-collapse collapse {{ $showAddMessage ? 'show' : '' }}"
                        aria-labelledby="ticketComposerHeading"
                        data-bs-parent="#ticketComposerAccordion">
                        <div class="accordion-body">

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
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const type = document.getElementById('type');
        const visibility = document.getElementById('visibility');
        const replyToContact = document.getElementById('reply_to_contact');
        const internalNoteHint = document.getElementById('internal_note_hint');
        const replyShortcut = document.getElementById('ticketReplyShortcut');
        const composer = document.getElementById('ticketComposerCollapse');
        const body = document.getElementById('body');

        const syncMessageType = function (value) {
            if (! type || ! visibility || ! replyToContact || ! internalNoteHint) {
                return;
            }

            if (value && Array.from(type.options).some((option) => option.value === value)) {
                type.value = value;
            }

            const isInternal = type.value === 'internal_note';

            visibility.value = isInternal ? 'internal' : 'public';
            replyToContact.classList.toggle('d-none', isInternal);
            internalNoteHint.classList.toggle('d-none', ! isInternal);
        };

        type.addEventListener('change', function () {
            syncMessageType();
        });

        replyShortcut.addEventListener('click', function () {
            syncMessageType(this.dataset.messageType);

            if (window.bootstrap && composer) {
                window.bootstrap.Collapse.getOrCreateInstance(composer, { toggle: false }).show();
            } else {
                composer?.classList.add('show');
            }

            window.setTimeout(function () {
                composer?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                body?.focus({ preventScroll: true });
            }, 150);
        });
    });
</script>
@endsection

@section('sidebar')
    <x-nav.work-menu />
@endsection

@section('rightbar')
    <div class="accordion accordion-flush" id="ticketRightbarAccordion">
        <div class="accordion-item border rounded mb-2 overflow-hidden">
            <h2 class="accordion-header" id="ticketDetailsHeading">
                <button
                    class="accordion-button py-2 px-3"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ticketDetailsCollapse"
                    aria-expanded="true"
                    aria-controls="ticketDetailsCollapse">
                    <span class="d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <span>Details</span>
                    </span>
                </button>
            </h2>
            <div
                id="ticketDetailsCollapse"
                class="accordion-collapse collapse show"
                aria-labelledby="ticketDetailsHeading"
                data-bs-parent="#ticketRightbarAccordion">
                <div class="accordion-body p-3">
                    <div class="small">
                        @php
                            $detailRows = [
                                'Queue' => $ticket->queue?->name,
                                'Status' => $ticket->status?->name,
                                'Priority' => $ticket->priority ? 'P' . $ticket->priority->level . ' ' . $ticket->priority->name : null,
                                'Category' => $ticket->category?->name,
                                'Owner' => $ticket->owner?->name ?? 'Unassigned',
                                'Site' => $ticket->site?->name,
                                'Asset' => $ticket->asset?->name,
                                'Channel' => ucfirst($ticket->channel),
                                'Resolved' => $ticket->resolved_at?->format('Y-m-d H:i'),
                                'Closed' => $ticket->closed_at?->format('Y-m-d H:i'),
                                'Created' => $ticket->created_at?->format('Y-m-d H:i'),
                                'Updated' => $ticket->updated_at?->format('Y-m-d H:i'),
                            ];
                        @endphp

                        <div class="row g-1">
                            @foreach($detailRows as $label => $value)
                                <div class="col-6">
                                    <div class="border rounded bg-light px-2 py-1 h-100">
                                        <div class="text-muted text-uppercase" style="font-size: .68rem;">{{ $label }}</div>
                                        <div class="fw-semibold text-truncate">{{ filled($value) ? $value : '-' }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-2">
                            <div class="text-muted text-uppercase mb-1" style="font-size: .68rem;">Tags</div>
                            <div class="d-flex flex-wrap gap-1">
                                @forelse($ticket->tags as $tag)
                                    <span class="badge text-bg-light border">{{ $tag->name }}</span>
                                @empty
                                    <span class="text-muted">-</span>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <a href="{{ route('tech.tickets.edit', $ticket) }}" class="btn btn-sm btn-outline-primary w-100">Edit ticket</a>
                    </div>
                    <form method="POST" action="{{ route('tech.tickets.assign', $ticket) }}" class="mt-2">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Run assignment</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="accordion-item border rounded overflow-hidden">
            <h2 class="accordion-header" id="ticketEventsHeading">
                <button
                    class="accordion-button collapsed py-2 px-3"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ticketEventsCollapse"
                    aria-expanded="false"
                    aria-controls="ticketEventsCollapse">
                    <span class="d-flex align-items-center gap-2">
                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                        <span>Events</span>
                        <span class="badge text-bg-secondary">{{ $ticket->events->count() }}</span>
                    </span>
                </button>
            </h2>
            <div
                id="ticketEventsCollapse"
                class="accordion-collapse collapse"
                aria-labelledby="ticketEventsHeading"
                data-bs-parent="#ticketRightbarAccordion">
                <div class="accordion-body p-3">
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
                </div>
            </div>
        </div>
    </div>
@endsection
