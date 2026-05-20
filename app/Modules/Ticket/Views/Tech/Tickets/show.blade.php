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
            @if ($ticket->is_unread && ($ticketActions['mark_read'] ?? true))
                <!-- Marks the ticket and its current messages as read without changing ticket status or ownership. -->
                <form method="POST" action="{{ route('tech.tickets.read', $ticket) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary">Mark as read</button>
                </form>
            @endif
            @if (! $ticket->status?->is_closed)
                @php
                    $closeDisabledReason = $closeTransition
                        ? $closeTransition->disabled_reason
                        : 'Ticket must be marked as solved before it can be closed.';
                    $canUseCloseAction = ($ticketActions['close'] ?? true) && $closeTransition && ! $closeDisabledReason;
                @endphp
                <!-- Close remains visible, but workflow requirements decide whether it can be used. -->
                @if ($canUseCloseAction)
                    <form method="POST" action="{{ route('tech.tickets.close', $ticket) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary">Close</button>
                    </form>
                @else
                    <button type="button" class="btn btn-outline-secondary" disabled title="{{ $closeDisabledReason }}">Close</button>
                @endif
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
        $canReplyToContact = filled($ticket->contact?->email) && ($ticketActions['customer_reply'] ?? true);
        $canAddInternalNote = $ticketActions['add_internal_note'] ?? true;
        $defaultMessageType = $canReplyToContact ? 'customer_reply' : 'internal_note';
        $selectedMessageType = old('type', $defaultMessageType);
        $selectedReplyIntent = old('reply_intent', \App\Modules\Ticket\Support\TicketAction::CUSTOMER_UPDATE);
        $selectedReplyContactId = old('reply_contact_id', $ticket->contact_id);
        $selectedNotifyUserId = old('notify_user_id');
        $showAddMessage = old('_message_form') || old('body') || old('type');
        $showAddTimeModal = old('_time_entry_form');
        // Activity combines conversation and time records into one technician-facing timeline.
        $activityItems = $ticket->messages
            ->map(fn ($message) => ['type' => 'message', 'date' => $message->created_at, 'record' => $message])
            ->concat($ticket->timeEntries->map(fn ($entry) => ['type' => 'time', 'date' => $entry->created_at, 'record' => $entry]))
            ->sortByDesc('date')
            ->values();
    @endphp

    <div class="row">
        <div class="col-12">
            {{-- Original request summary --}}
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between gap-3 py-2">
                    <h2 class="h6 mb-0">{{ $ticket->subject }}</h2>
                    @if($ticketActions['update_fields'] ?? true)
                        <x-buttons.editlink
                            url="{{ route('tech.tickets.edit', $ticket) }}"
                            class="btn btn-sm btn-outline-primary bi bi-pencil">
                            Edit ticket
                        </x-buttons.editlink>
                    @endif
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
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="modal"
                        data-bs-target="#ticketAddTimeModal">
                        <i class="bi bi-clock" aria-hidden="true"></i>
                        Add time
                    </button>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Activity</div>
                <div class="card-body">
                    @if($activityItems->isNotEmpty())
                        <div class="accordion accordion-flush" id="ticketConversationAccordion">
                            @foreach ($activityItems as $activityItem)
                                @php
                                    $message = $activityItem['type'] === 'message' ? $activityItem['record'] : null;
                                    $timeEntry = $activityItem['type'] === 'time' ? $activityItem['record'] : null;
                                @endphp

                                @if($timeEntry)
                                    @php
                                        $timeCollapseId = 'ticketTimeEntryCollapse' . $timeEntry->id;
                                        $timeHeadingId = 'ticketTimeEntryHeading' . $timeEntry->id;
                                        $timeText = $timeEntry->invoice_text ?: $timeEntry->note;
                                    @endphp
                                    <div class="accordion-item">
                                        <h2 class="accordion-header" id="{{ $timeHeadingId }}">
                                            <button
                                                class="accordion-button collapsed py-2 px-0"
                                                type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#{{ $timeCollapseId }}"
                                                aria-expanded="false"
                                                aria-controls="{{ $timeCollapseId }}">
                                                <span class="d-flex align-items-center gap-2 w-100 pe-3 text-start min-w-0">
                                                    <span class="fw-semibold flex-shrink-0">Time</span>
                                                    <span class="badge text-bg-light border flex-shrink-0">{{ $timeEntry->minutes }} min</span>
                                                    <span class="small text-muted text-truncate flex-shrink-0" style="max-width: 14rem;">{{ $timeEntry->user?->name ?? 'Technician' }}</span>
                                                    <span class="small text-body text-truncate min-w-0 flex-grow-1">
                                                        {{ filled($timeText) ? \Illuminate\Support\Str::limit($timeText, 120) : ($timeEntry->rate_name ?? 'Time registered') }}
                                                    </span>
                                                    <span class="text-muted small flex-shrink-0">{{ $timeEntry->created_at?->diffForHumans() }}</span>
                                                </span>
                                            </button>
                                        </h2>
                                        <div
                                            id="{{ $timeCollapseId }}"
                                            class="accordion-collapse collapse"
                                            aria-labelledby="{{ $timeHeadingId }}">
                                            <div class="accordion-body px-0 pt-2 pb-3">
                                                <!-- Time activity rows show billing intent without settling billing or contract minutes. -->
                                                <div class="row g-2 small mb-2">
                                                    <div class="col-md-3">
                                                        <div class="border rounded bg-light px-2 py-1 h-100">
                                                            <div class="text-muted text-uppercase" style="font-size: .68rem;">Date</div>
                                                            <div class="fw-semibold">{{ $timeEntry->work_date?->format('Y-m-d') ?? '-' }}</div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="border rounded bg-light px-2 py-1 h-100">
                                                            <div class="text-muted text-uppercase" style="font-size: .68rem;">Time</div>
                                                            <div class="fw-semibold">{{ $timeEntry->minutes }} min</div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="border rounded bg-light px-2 py-1 h-100">
                                                            <div class="text-muted text-uppercase" style="font-size: .68rem;">Rate</div>
                                                            <div class="fw-semibold text-truncate">{{ $timeEntry->rate_name ?? '-' }}</div>
                                                        </div>
                                                    </div>
                                                </div>
                                                @if(filled($timeEntry->invoice_text))
                                                    <div class="small text-muted text-uppercase mb-1" style="font-size: .68rem;">Invoice text</div>
                                                    <div style="white-space: pre-wrap;">{{ $timeEntry->invoice_text }}</div>
                                                @endif
                                                @if(filled($timeEntry->note))
                                                    <div class="small text-muted text-uppercase mt-3 mb-1" style="font-size: .68rem;">Internal note</div>
                                                    <div style="white-space: pre-wrap;">{{ $timeEntry->note }}</div>
                                                @endif
                                                <div class="d-flex flex-wrap gap-1 mt-3">
                                                    <span class="badge text-bg-light border">{{ ucfirst(str_replace('_', ' ', $timeEntry->billing_basis ?: 'manual')) }}</span>
                                                    <span class="badge text-bg-light border">Billing: {{ ucfirst($timeEntry->billing_status) }}</span>
                                                    <span class="badge text-bg-light border">Timebank: {{ ucfirst($timeEntry->timebank_status) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    @continue
                                @endif

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
                                    $isSolution = (bool) ($message->metadata['is_solution'] ?? false);
                                    $canMarkSolution = $message->author_type === 'user'
                                        && $message->type === 'customer_reply'
                                        && $message->visibility === 'public'
                                        && ! $isSolution;
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
                                                @if ($isSolution)
                                                    <span class="text-success small fw-semibold flex-shrink-0">Solution</span>
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
                                            @if ($canMarkSolution)
                                                <!-- Public technician replies can become the workflow solution required by solved transitions. -->
                                                <div class="d-flex justify-content-end mb-2">
                                                    <form method="POST" action="{{ route('tech.tickets.messages.solution', [$ticket, $message]) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-success">Mark as solution</button>
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
                        <p class="text-muted mb-0">No activity yet.</p>
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

                                <input type="hidden" name="_message_form" value="1">
                                <input id="visibility" name="visibility" type="hidden" value="{{ $selectedMessageType === 'internal_note' ? 'internal' : 'public' }}">

                                <div class="row g-2 mb-3">
                                    <div class="col-md-6">
                                        <label for="type" class="form-label">Message type</label>
                                        <select id="type" name="type" class="form-select @error('type') is-invalid @enderror">
                                            @if ($canReplyToContact)
                                                <option value="customer_reply" @selected($selectedMessageType === 'customer_reply')>Reply to contact</option>
                                            @endif
                                            @if($canAddInternalNote)
                                                <option value="internal_note" @selected($selectedMessageType === 'internal_note')>Internal note</option>
                                            @endif
                                        </select>
                                        @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div id="reply_intent_group" class="col-md-6 @if ($selectedMessageType === 'internal_note') d-none @endif">
                                        <label for="reply_intent" class="form-label">Reply intent</label>
                                        <select id="reply_intent" name="reply_intent" class="form-select @error('reply_intent') is-invalid @enderror">
                                            <option value="customer_update" @selected($selectedReplyIntent === 'customer_update')>Update customer</option>
                                            <option value="request_customer_input" @selected($selectedReplyIntent === 'request_customer_input')>Request customer input</option>
                                            <option value="send_solution" @selected($selectedReplyIntent === 'send_solution')>Send solution</option>
                                        </select>
                                        @error('reply_intent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div id="notify_technician_group" class="col-md-6 @if ($selectedMessageType !== 'internal_note') d-none @endif">
                                        <label for="notify_user_id" class="form-label">Notify technician</label>
                                        <select id="notify_user_id" name="notify_user_id" class="form-select @error('notify_user_id') is-invalid @enderror">
                                            <option value="">Do not notify</option>
                                            @foreach($technicians as $technician)
                                                <option value="{{ $technician->id }}" @selected((string) $selectedNotifyUserId === (string) $technician->id)>{{ $technician->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('notify_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>

                                <div id="reply_recipient_group" class="row g-2 mb-3 @if ($selectedMessageType === 'internal_note') d-none @endif">
                                    <div class="col-md-6">
                                        <label for="reply_contact_id" class="form-label">Contact</label>
                                        <select id="reply_contact_id" name="reply_contact_id" class="form-select @error('reply_contact_id') is-invalid @enderror">
                                            @foreach($replyContacts as $replyContact)
                                                <option value="{{ $replyContact->id }}" @selected((string) $selectedReplyContactId === (string) $replyContact->id)>
                                                    {{ $replyContact->name }} &lt;{{ $replyContact->email }}&gt;
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('reply_contact_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label for="cc" class="form-label">CC</label>
                                        <input id="cc" name="cc" type="text" class="form-control @error('cc') is-invalid @enderror" value="{{ old('cc') }}" placeholder="thirdparty@example.com">
                                        @error('cc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
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

{{-- Time registration modal --}}
<div class="modal fade" id="ticketAddTimeModal" tabindex="-1" aria-labelledby="ticketAddTimeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="{{ route('tech.tickets.time-entries.store', $ticket) }}">
                @csrf
                <input type="hidden" name="_time_entry_form" value="1">

                <div class="modal-header">
                    <h2 class="modal-title h6" id="ticketAddTimeModalLabel">Add time</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="time_work_date" class="form-label">Date</label>
                            <input id="time_work_date" name="work_date" type="date" class="form-control @error('work_date') is-invalid @enderror" value="{{ old('work_date', now()->toDateString()) }}" required>
                            @error('work_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="time_minutes" class="form-label">Minutes</label>
                            <input id="time_minutes" name="minutes" type="number" min="1" max="1440" step="1" class="form-control @error('minutes') is-invalid @enderror" value="{{ old('minutes', 30) }}" required>
                            @error('minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="time_rate_key" class="form-label">Time rate</label>
                            <select id="time_rate_key" name="rate_key" class="form-select @error('rate_key') is-invalid @enderror" required>
                                <option value="">Select rate</option>
                                @foreach($timeRateOptions as $rateOption)
                                    <option value="{{ $rateOption['key'] }}" @selected(old('rate_key') === $rateOption['key'])>
                                        {{ $rateOption['label'] }} - {{ $rateOption['description'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('rate_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @if($timeRateOptions->isEmpty())
                                <div class="form-text text-danger">No active time rates are available for this ticket.</div>
                            @endif
                        </div>
                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <label for="time_invoice_text" class="form-label mb-0">Invoice text</label>
                                <button
                                    id="ticketTimeAiDraft"
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    data-draft-url="{{ route('tech.tickets.time-entries.draft', $ticket) }}">
                                    <i class="bi bi-stars" aria-hidden="true"></i>
                                    AI draft
                                </button>
                            </div>
                            <textarea id="time_invoice_text" name="invoice_text" rows="4" class="form-control mt-1 @error('invoice_text') is-invalid @enderror" required>{{ old('invoice_text') }}</textarea>
                            @error('invoice_text')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div id="ticketTimeAiDraftStatus" class="form-text"></div>
                        </div>
                        <div class="col-12">
                            <label for="time_note" class="form-label">Internal note</label>
                            <textarea id="time_note" name="note" rows="2" class="form-control @error('note') is-invalid @enderror">{{ old('note') }}</textarea>
                            @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" @disabled($timeRateOptions->isEmpty())>Save time</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const type = document.getElementById('type');
        const visibility = document.getElementById('visibility');
        const replyRecipientGroup = document.getElementById('reply_recipient_group');
        const replyIntentGroup = document.getElementById('reply_intent_group');
        const notifyTechnicianGroup = document.getElementById('notify_technician_group');
        const replyShortcut = document.getElementById('ticketReplyShortcut');
        const composer = document.getElementById('ticketComposerCollapse');
        const body = document.getElementById('body');
        const addTimeModal = document.getElementById('ticketAddTimeModal');
        const shouldShowAddTimeModal = @json((bool) $showAddTimeModal);
        const timeAiDraft = document.getElementById('ticketTimeAiDraft');
        const timeInvoiceText = document.getElementById('time_invoice_text');
        const timeRateSelect = document.getElementById('time_rate_key');
        const timeAiDraftStatus = document.getElementById('ticketTimeAiDraftStatus');
        const timeMinutes = document.getElementById('time_minutes');
        const timeWorkDate = document.getElementById('time_work_date');
        const stopwatchDisplay = document.getElementById('ticketStopwatchDisplay');
        const stopwatchState = document.getElementById('ticketStopwatchState');
        const stopwatchStartGroup = document.getElementById('ticketStopwatchStartGroup');
        const stopwatchControls = document.getElementById('ticketStopwatchControls');
        const stopwatchStart = document.getElementById('ticketStopwatchStart');
        const stopwatchToggle = document.getElementById('ticketStopwatchToggle');
        const stopwatchStop = document.getElementById('ticketStopwatchStop');
        const stopwatchStorageKey = @json('ticket-stopwatch-' . $ticket->ticket_key);

        const syncMessageType = function (value) {
            if (! type || ! visibility || ! replyRecipientGroup || ! replyIntentGroup || ! notifyTechnicianGroup) {
                return;
            }

            if (value && Array.from(type.options).some((option) => option.value === value)) {
                type.value = value;
            }

            const isInternal = type.value === 'internal_note';

            visibility.value = isInternal ? 'internal' : 'public';
            replyRecipientGroup.classList.toggle('d-none', isInternal);
            replyIntentGroup.classList.toggle('d-none', isInternal);
            notifyTechnicianGroup.classList.toggle('d-none', ! isInternal);
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

        if (shouldShowAddTimeModal && window.bootstrap && addTimeModal) {
            window.bootstrap.Modal.getOrCreateInstance(addTimeModal).show();
        }

        const defaultStopwatchState = {
            elapsedMs: 0,
            startedAt: null,
            running: false,
        };
        let stopwatch = { ...defaultStopwatchState };

        const loadStopwatch = function () {
            try {
                stopwatch = { ...defaultStopwatchState, ...(JSON.parse(localStorage.getItem(stopwatchStorageKey)) || {}) };
            } catch (error) {
                stopwatch = { ...defaultStopwatchState };
            }
        };

        const saveStopwatch = function () {
            localStorage.setItem(stopwatchStorageKey, JSON.stringify(stopwatch));
        };

        const currentElapsedMs = function () {
            if (! stopwatch.running || ! stopwatch.startedAt) {
                return stopwatch.elapsedMs;
            }

            return stopwatch.elapsedMs + Math.max(0, Date.now() - stopwatch.startedAt);
        };

        const formatElapsed = function (milliseconds) {
            const totalSeconds = Math.floor(milliseconds / 1000);
            const hours = String(Math.floor(totalSeconds / 3600)).padStart(2, '0');
            const minutes = String(Math.floor((totalSeconds % 3600) / 60)).padStart(2, '0');
            const seconds = String(totalSeconds % 60).padStart(2, '0');

            return `${hours}:${minutes}:${seconds}`;
        };

        const syncStopwatchUi = function () {
            if (! stopwatchDisplay || ! stopwatchState) {
                return;
            }

            const elapsed = currentElapsedMs();
            stopwatchDisplay.textContent = formatElapsed(elapsed);
            stopwatchState.textContent = stopwatch.running
                ? 'Running'
                : (elapsed > 0 ? 'Paused' : 'Not running');

            stopwatchStartGroup?.classList.toggle('d-none', elapsed > 0);
            stopwatchControls?.classList.toggle('d-none', elapsed <= 0);

            if (stopwatchStart) {
                stopwatchStart.disabled = stopwatch.running;
                stopwatchStart.innerHTML = '<i class="bi bi-play-fill" aria-hidden="true"></i> Start';
            }

            if (stopwatchToggle) {
                stopwatchToggle.disabled = elapsed <= 0;
                stopwatchToggle.innerHTML = stopwatch.running
                    ? '<i class="bi bi-pause-fill" aria-hidden="true"></i> Pause'
                    : '<i class="bi bi-play" aria-hidden="true"></i> Resume';
            }

            if (stopwatchStop) {
                stopwatchStop.disabled = elapsed <= 0;
            }
        };

        const openTimeModalFromStopwatch = function (elapsedMs) {
            const minutes = Math.max(1, Math.ceil(elapsedMs / 60000));
            const today = new Date().toISOString().slice(0, 10);

            if (timeMinutes) {
                timeMinutes.value = minutes;
            }

            if (timeWorkDate) {
                timeWorkDate.value = today;
            }

            if (window.bootstrap && addTimeModal) {
                window.bootstrap.Modal.getOrCreateInstance(addTimeModal).show();
            }
        };

        loadStopwatch();
        syncStopwatchUi();
        window.setInterval(syncStopwatchUi, 1000);

        stopwatchStart?.addEventListener('click', function () {
            stopwatch = {
                elapsedMs: 0,
                startedAt: Date.now(),
                running: true,
            };
            saveStopwatch();
            syncStopwatchUi();
        });

        stopwatchToggle?.addEventListener('click', function () {
            if (currentElapsedMs() <= 0) {
                return;
            }

            if (stopwatch.running) {
                stopwatch.elapsedMs = currentElapsedMs();
                stopwatch.startedAt = null;
                stopwatch.running = false;
            } else {
                stopwatch.startedAt = Date.now();
                stopwatch.running = true;
            }

            saveStopwatch();
            syncStopwatchUi();
        });

        stopwatchStop?.addEventListener('click', function () {
            const elapsed = currentElapsedMs();

            if (elapsed <= 0) {
                return;
            }

            stopwatch = { ...defaultStopwatchState };
            localStorage.removeItem(stopwatchStorageKey);
            syncStopwatchUi();
            openTimeModalFromStopwatch(elapsed);
        });

        timeAiDraft?.addEventListener('click', async function () {
            const originalText = timeAiDraft.innerHTML;
            timeAiDraft.disabled = true;
            timeAiDraft.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Drafting';
            timeAiDraftStatus.className = 'form-text text-muted';
            timeAiDraftStatus.textContent = 'Using selected rate, previous time entries, replies, and notes as context.';

            try {
                const response = await fetch(timeAiDraft.dataset.draftUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': @json(csrf_token()),
                    },
                    body: JSON.stringify({
                        existing_text: timeInvoiceText?.value || '',
                        rate_key: timeRateSelect?.value || '',
                    }),
                });
                const payload = await response.json();

                if (! response.ok) {
                    throw new Error(payload.message || 'AI draft failed.');
                }

                if (payload.text && timeInvoiceText) {
                    timeInvoiceText.value = payload.text;
                }

                timeAiDraftStatus.className = 'form-text text-success';
                timeAiDraftStatus.textContent = 'Draft inserted.';
            } catch (error) {
                timeAiDraftStatus.className = 'form-text text-danger';
                timeAiDraftStatus.textContent = error.message || 'AI draft failed.';
            } finally {
                timeAiDraft.disabled = false;
                timeAiDraft.innerHTML = originalText;
            }
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
            <h2 class="accordion-header" id="ticketTimeHeading">
                <button
                    class="accordion-button py-2 px-3"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ticketTimeCollapse"
                    aria-expanded="true"
                    aria-controls="ticketTimeCollapse">
                    <span class="d-flex align-items-center gap-2">
                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                        <span>Time</span>
                        <span class="badge text-bg-light border">{{ $ticket->timeEntries->sum('minutes') }} min</span>
                    </span>
                </button>
            </h2>
            <div
                id="ticketTimeCollapse"
                class="accordion-collapse collapse show"
                aria-labelledby="ticketTimeHeading"
                data-bs-parent="#ticketRightbarAccordion">
                <div class="accordion-body p-3">
                    <!-- The stopwatch is local draft state. Saving still happens through the Add time modal. -->
                    <div class="text-center border rounded bg-light px-2 py-3">
                        <div id="ticketStopwatchDisplay" class="fw-semibold font-monospace" style="font-size: 1.75rem;">00:00:00</div>
                        <div id="ticketStopwatchState" class="small text-muted mt-1">Not running</div>
                    </div>
                    <div id="ticketStopwatchStartGroup" class="d-grid gap-2 mt-3">
                        <button id="ticketStopwatchStart" type="button" class="btn btn-sm btn-primary">
                            <i class="bi bi-play-fill" aria-hidden="true"></i>
                            Start
                        </button>
                    </div>
                    <div id="ticketStopwatchControls" class="row g-2 mt-3 d-none">
                        <div class="col-6">
                            <button id="ticketStopwatchToggle" type="button" class="btn btn-sm btn-outline-secondary w-100" disabled>
                                <i class="bi bi-pause-fill" aria-hidden="true"></i>
                                Pause
                            </button>
                        </div>
                        <div class="col-6">
                            <button id="ticketStopwatchStop" type="button" class="btn btn-sm btn-outline-primary w-100" disabled>
                                <i class="bi bi-stop-fill" aria-hidden="true"></i>
                                Stop
                            </button>
                        </div>
                    </div>
                    <div class="small text-muted mt-2">
                        Registered total: {{ $ticket->timeEntries->sum('minutes') }} min
                    </div>
                </div>
            </div>
        </div>

        <div class="accordion-item border rounded mb-2 overflow-hidden">
            <h2 class="accordion-header" id="ticketWorkflowHeading">
                <button
                    class="accordion-button collapsed py-2 px-3"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ticketWorkflowCollapse"
                    aria-expanded="false"
                    aria-controls="ticketWorkflowCollapse">
                    <span class="d-flex align-items-center gap-2">
                        <i class="bi bi-diagram-3" aria-hidden="true"></i>
                        <span>Workflow</span>
                        @if($ticket->workflow)
                            <span class="badge text-bg-light border">{{ $ticket->workflow->name }}</span>
                        @endif
                    </span>
                </button>
            </h2>
            <div
                id="ticketWorkflowCollapse"
                class="accordion-collapse collapse"
                aria-labelledby="ticketWorkflowHeading"
                data-bs-parent="#ticketRightbarAccordion">
                <div class="accordion-body p-3">
                    <div class="small mb-2">
                        <div class="text-muted text-uppercase" style="font-size: .68rem;">Current state</div>
                        <div class="fw-semibold">{{ $ticket->status?->name ?? '-' }}</div>
                    </div>

                    <div class="d-grid gap-2">
                        @forelse($workflowTransitions as $transition)
                            @php
                                $disabledReason = $transition->disabled_reason;
                            @endphp
                            <form method="POST" action="{{ route('tech.tickets.workflow.transition', [$ticket, $transition]) }}">
                                @csrf
                                <button
                                    type="submit"
                                    class="btn btn-sm {{ $disabledReason ? 'btn-outline-secondary' : 'btn-outline-primary' }} w-100 text-start"
                                    @disabled($disabledReason)
                                    @if($disabledReason) title="{{ $disabledReason }}" @endif>
                                    <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                                    {{ $transition->label }}
                                    <span class="text-muted">to {{ $transition->toStatus?->name }}</span>
                                </button>
                                @if($disabledReason)
                                    <div class="small text-muted mt-1">{{ $disabledReason }}</div>
                                @endif
                            </form>
                        @empty
                            <p class="text-muted small mb-0">No workflow transitions available.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="accordion-item border rounded mb-2 overflow-hidden">
            <h2 class="accordion-header" id="ticketDetailsHeading">
                <button
                    class="accordion-button collapsed py-2 px-3"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ticketDetailsCollapse"
                    aria-expanded="false"
                    aria-controls="ticketDetailsCollapse">
                    <span class="d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <span>Details</span>
                    </span>
                </button>
            </h2>
            <div
                id="ticketDetailsCollapse"
                class="accordion-collapse collapse"
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

                    @if($ticketActions['update_fields'] ?? true)
                        <div class="mt-3">
                            <a href="{{ route('tech.tickets.edit', $ticket) }}" class="btn btn-sm btn-outline-primary w-100">Edit ticket</a>
                        </div>
                    @endif
                    @if($ticketActions['assign_owner'] ?? true)
                        <form method="POST" action="{{ route('tech.tickets.assign', $ticket) }}" class="mt-2">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Run assignment</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="accordion-item border rounded mb-2 overflow-hidden">
            <h2 class="accordion-header" id="ticketKnowledgeHeading">
                <button
                    class="accordion-button collapsed py-2 px-3"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ticketKnowledgeCollapse"
                    aria-expanded="false"
                    aria-controls="ticketKnowledgeCollapse">
                    <span class="d-flex align-items-center gap-2">
                        <i class="bi bi-journal-text" aria-hidden="true"></i>
                        <span>Knowledge</span>
                        <span class="badge text-bg-secondary">{{ $knowledgeSuggestions->count() }}</span>
                    </span>
                </button>
            </h2>
            <div
                id="ticketKnowledgeCollapse"
                class="accordion-collapse collapse"
                aria-labelledby="ticketKnowledgeHeading"
                data-bs-parent="#ticketRightbarAccordion">
                <div class="accordion-body p-3">
                    @forelse ($knowledgeSuggestions as $article)
                        <!-- Suggested articles are ranked from ticket context and opened separately so the ticket workflow stays in place. -->
                        <a
                            href="{{ route('tech.knowledge.show', $article) }}"
                            target="_blank"
                            rel="noopener"
                            class="d-block text-decoration-none text-reset border rounded bg-light px-2 py-2 mb-2">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="fw-semibold small lh-sm">{{ $article->title }}</div>
                                <i class="bi bi-box-arrow-up-right text-muted small flex-shrink-0" aria-hidden="true"></i>
                            </div>
                            <div class="text-muted small mt-1">
                                {{ $article->knowledgeBook?->name ?? $article->knowledgeShelf?->name ?? $article->category?->name ?? 'Knowledge article' }}
                            </div>
                            <div class="text-muted small mt-1">
                                {{ \Illuminate\Support\Str::limit(trim(strip_tags($article->body_markdown ?: $article->body_html)), 110) }}
                            </div>
                        </a>
                    @empty
                        <p class="text-muted small mb-0">No matching articles found.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="accordion-item border rounded mb-2 overflow-hidden">
            <h2 class="accordion-header" id="ticketSlaHeading">
                <button
                    class="accordion-button collapsed py-2 px-3"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ticketSlaCollapse"
                    aria-expanded="false"
                    aria-controls="ticketSlaCollapse">
                    <span class="d-flex align-items-center gap-2">
                        <i class="bi bi-stopwatch" aria-hidden="true"></i>
                        <span>SLA</span>
                        @if($ticket->sla)
                            <span class="badge text-bg-light border">{{ $ticket->sla->name }}</span>
                        @endif
                    </span>
                </button>
            </h2>
            <div
                id="ticketSlaCollapse"
                class="accordion-collapse collapse"
                aria-labelledby="ticketSlaHeading"
                data-bs-parent="#ticketRightbarAccordion">
                <div class="accordion-body p-3">
                    @php
                        $slaSource = match ($ticket->sla_source) {
                            'ticket_rule' => 'Ticket rule',
                            'contract' => 'Contract',
                            'default' => 'Default',
                            default => 'Not selected',
                        };
                        $firstResponseClass = $ticket->first_response_due_at?->isPast() && ! $ticket->first_responded_at ? 'text-danger' : 'text-body';
                        $resolveClass = $ticket->resolve_due_at?->isPast() && ! $ticket->resolved_at ? 'text-danger' : 'text-body';
                    @endphp

                    <div class="row g-1 small">
                        <div class="col-6">
                            <div class="border rounded bg-light px-2 py-1 h-100">
                                <div class="text-muted text-uppercase" style="font-size: .68rem;">Policy</div>
                                <div class="fw-semibold text-truncate">{{ $ticket->sla?->name ?? ($ticket->sla_snapshot['name'] ?? '-') }}</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded bg-light px-2 py-1 h-100">
                                <div class="text-muted text-uppercase" style="font-size: .68rem;">Source</div>
                                <div class="fw-semibold text-truncate">{{ $slaSource }}</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded bg-light px-2 py-1 h-100">
                                <div class="text-muted text-uppercase" style="font-size: .68rem;">First response</div>
                                <div class="fw-semibold {{ $firstResponseClass }}">{{ $ticket->first_response_due_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                @if($ticket->first_responded_at)
                                    <div class="text-muted" style="font-size: .72rem;">Done {{ $ticket->first_responded_at->format('Y-m-d H:i') }}</div>
                                @endif
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded bg-light px-2 py-1 h-100">
                                <div class="text-muted text-uppercase" style="font-size: .68rem;">Resolve</div>
                                <div class="fw-semibold {{ $resolveClass }}">{{ $ticket->resolve_due_at?->format('Y-m-d H:i') ?? '-' }}</div>
                            </div>
                        </div>
                    </div>

                    @if($ticket->sla_snapshot)
                        <div class="mt-2 small text-muted">
                            {{ ucfirst($ticket->sla_snapshot['priority_band'] ?? 'medium') }} target:
                            {{ $ticket->sla_snapshot['first_response_value'] ?? '-' }} {{ $ticket->sla_snapshot['first_response_unit'] ?? '' }} response,
                            {{ $ticket->sla_snapshot['resolve_value'] ?? '-' }} {{ $ticket->sla_snapshot['resolve_unit'] ?? '' }} resolve
                        </div>
                    @endif
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
