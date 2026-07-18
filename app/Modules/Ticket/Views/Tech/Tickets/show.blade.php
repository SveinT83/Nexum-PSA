@extends('layouts.default_tech')

@section('title', $ticket->ticket_key)

@section('pageName')
    <h3>Tickets</h3>
@endsection

@section('pageHeader')
    <div class="d-flex flex-column flex-xl-row align-items-xl-center gap-2 w-100">
        <div class="flex-shrink-0">
            <div class="d-flex align-items-center gap-2 mb-1">
                <h1 class="mb-0">{{ $ticket->ticket_key }}</h1>
                @if ($ticket->is_unread)
                    <span class="badge text-bg-primary">Unread</span>
                @endif
            </div>
            <p class="text-muted mb-0">{{ $ticket->subject }}</p>
        </div>

        @include('ticket::Tech.Tickets.partials.workflow-stepper')

        <!-- Ticket-level actions stay visually separate from workflow progress. -->
        <div class="d-flex flex-wrap gap-2 flex-shrink-0 ms-xl-auto">
            @if ($ticket->is_unread && ($ticketActions['mark_read'] ?? true))
                <!-- Marks the ticket and its current messages as read without changing ticket status or ownership. -->
                <form method="POST" action="{{ route('tech.tickets.read', $ticket) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary">Mark as read</button>
                </form>
            @endif
            @if (! $ticket->status?->is_closed)
                @php
                    $closeDecision = $ticketActionDecisions['close'] ?? ['visible' => true, 'allowed' => true, 'reason' => null];
                    $terminalTransition = collect($workflowTransitionDecisions ?? [])->first(fn ($decision) => (bool) data_get($decision, 'target_state.is_terminal'));
                    $closeDisabledReason = $closeDecision['reason'] ?? ($terminalTransition['disabled_reason'] ?? null);
                    $canUseCloseAction = $closeDecision['allowed'] && (! $ticket->workflow_version_id || ($terminalTransition['allowed'] ?? false));
                @endphp
                <!-- Close remains visible, but workflow requirements decide whether it can be used. -->
                @if ($closeDecision['visible'] && $canUseCloseAction)
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ticketCloseOutcomeModal">Close</button>
                @elseif($closeDecision['visible'])
                    <button type="button" class="btn btn-outline-secondary" disabled title="{{ $closeDisabledReason }}">Close</button>
                @endif
            @endif
            @if($ticket->client_id && ($ticketActions['update_fields'] ?? true))
                <!-- Customer portal visibility is one-way from unpublished to published during normal ticket handling. -->
                @php
                    $portalPublished = $ticket->isPortalVisible();
                @endphp
                @if($portalPublished)
                    <span
                        class="btn btn-outline-success disabled"
                        aria-disabled="true"
                        title="This ticket is published in the customer portal.">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                        Published
                    </span>
                @else
                    <form method="POST" action="{{ route('tech.tickets.portal-visibility.update', $ticket) }}">
                        @csrf
                        <input type="hidden" name="portal_visible" value="1">
                        <button
                            type="submit"
                            class="btn btn-outline-secondary"
                            title="Publish to customer portal and enable customer replies"
                            aria-label="Publish ticket to customer portal"
                        >
                            <i class="bi bi-eye-slash" aria-hidden="true"></i>
                            Unpublished
                        </button>
                    </form>
                @endif
            @endif
            <a href="{{ route('tech.tickets.index') }}" class="btn btn-light">Back</a>
        </div>
    </div>
@endsection

@section('content')
<div class="container-fluid">
    @php
        $portalPublished = $ticket->isPortalVisible();
        $customerReplyBlockedByPortal = $ticket->client_id && ! $portalPublished;
        $canReplyToContact = $portalPublished && $replyContacts->isNotEmpty() && ($ticketActions['customer_reply'] ?? true);
        $ccSuggestionGroups = $ccContactSuggestions->groupBy('group');
        $canAddInternalNote = $ticketActions['add_internal_note'] ?? true;
        $allowInternalSolutionNotes = $solutionPolicy['allow_internal_solution_notes'] ?? true;
        $defaultMessageType = $canReplyToContact ? 'customer_reply' : 'internal_note';
        $selectedMessageType = old('type', $defaultMessageType);
        if (($selectedMessageType === 'customer_reply' && ! $canReplyToContact) || ($selectedMessageType === 'internal_solution' && ! $allowInternalSolutionNotes)) {
            $selectedMessageType = $defaultMessageType;
        }
        $selectedReplyIntent = old('reply_intent', \App\Modules\Ticket\Support\TicketAction::CUSTOMER_UPDATE);
        $selectedReplyContactId = old('reply_contact_id', $ticket->contact_id);
        $selectedNotifyUserId = old('notify_user_id');
        $showAddMessage = old('_message_form') || old('body') || old('type');
        $showAddTimeModal = old('_time_entry_form');
        $actionDecision = fn (string $key) => $ticketActionDecisions[$key] ?? ['visible' => true, 'allowed' => true, 'reason' => null];
        $defaultTimeRateKey = old('rate_key', $timeRateOptions->first()['key'] ?? null);
        // Activity combines conversation and time records into one technician-facing timeline.
        $activityItems = $ticket->messages
            ->map(fn ($message) => ['type' => 'message', 'date' => $message->created_at, 'record' => $message])
            ->concat($ticket->timeEntries->map(fn ($entry) => ['type' => 'time', 'date' => $entry->created_at, 'record' => $entry]))
            ->concat($ticket->costEntries->map(fn ($entry) => ['type' => 'cost', 'date' => $entry->created_at, 'record' => $entry]))
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
                    @if($actionDecision('register_time')['visible'])
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="modal"
                            data-bs-target="#ticketAddTimeModal"
                            @disabled(! $actionDecision('register_time')['allowed'])
                            title="{{ $actionDecision('register_time')['reason'] }}">
                            <i class="bi bi-clock" aria-hidden="true"></i>
                            Add time
                        </button>
                    @endif
                    @php
                        $canShowActualCost = $actionDecision('add_actual_cost')['visible'] || $actionDecision('reserve_item')['visible'];
                        $canAddActualCost = $actionDecision('add_actual_cost')['allowed'] || $actionDecision('reserve_item')['allowed'];
                    @endphp
                    @if($canShowActualCost)
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="modal"
                            data-bs-target="#ticketAddCostModal"
                            @disabled(! $canAddActualCost)
                            title="{{ $actionDecision('add_actual_cost')['reason'] ?: $actionDecision('reserve_item')['reason'] }}">
                            <i class="bi bi-box-seam" aria-hidden="true"></i>
                            Add actual cost
                        </button>
                    @endif
                </div>
            </div>

            @include('ticket::Tech.Tickets.partials.workflow-v3-panel')

            @include('relationship::Tech.Tickets.panel', [
                'ticket' => $ticket,
                'relationshipSyncLinks' => $relationshipSyncLinks ?? collect(),
                'availableRelationships' => $availableRelationships ?? collect(),
            ])

            <div class="card mb-3">
                <div class="card-header">Activity</div>
                <div class="card-body">
                    @if($activityItems->isNotEmpty())
                        <div class="accordion accordion-flush" id="ticketConversationAccordion">
                            @foreach ($activityItems as $activityItem)
                                @php
                                    $message = $activityItem['type'] === 'message' ? $activityItem['record'] : null;
                                    $timeEntry = $activityItem['type'] === 'time' ? $activityItem['record'] : null;
                                    $costEntry = $activityItem['type'] === 'cost' ? $activityItem['record'] : null;
                                @endphp

                                @if($costEntry)
                                    @php
                                        $costCollapseId = 'ticketCostEntryCollapse' . $costEntry->id;
                                        $costHeadingId = 'ticketCostEntryHeading' . $costEntry->id;
                                        $costText = $costEntry->invoice_text ?: $costEntry->item_name;
                                        $costTypeLabel = $costEntry->storage_item_id ? 'Storage cost' : 'Manual cost';
                                    @endphp
                                    <div class="accordion-item">
                                        <h2 class="accordion-header" id="{{ $costHeadingId }}">
                                            <button
                                                class="accordion-button collapsed py-2 px-0"
                                                type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#{{ $costCollapseId }}"
                                                aria-expanded="false"
                                                aria-controls="{{ $costCollapseId }}">
                                                <span class="d-flex align-items-center gap-2 w-100 pe-3 text-start min-w-0">
                                                    <span class="fw-semibold flex-shrink-0">{{ $costTypeLabel }}</span>
                                                    <span class="badge text-bg-light border flex-shrink-0">{{ $costEntry->quantity }} pcs</span>
                                                    <span class="small text-muted text-truncate flex-shrink-0" style="max-width: 14rem;">{{ $costEntry->user?->name ?? 'Technician' }}</span>
                                                    <span class="small text-body text-truncate min-w-0 flex-grow-1">
                                                        {{ \Illuminate\Support\Str::limit($costText, 120) }}
                                                    </span>
                                                    <span class="text-muted small flex-shrink-0">{{ $costEntry->created_at?->diffForHumans() }}</span>
                                                </span>
                                            </button>
                                        </h2>
                                        <div
                                            id="{{ $costCollapseId }}"
                                            class="accordion-collapse collapse"
                                            aria-labelledby="{{ $costHeadingId }}">
                                            <div class="accordion-body px-0 pt-2 pb-3">
                                                <!-- Cost rows reserve Storage stock for the ticket; billing decides invoicing later. -->
                                                <div class="d-flex justify-content-end gap-2 mb-2">
                                                    @if($costEntry->status === 'reserved')
                                                        @php
                                                            $canPickCostEntry = ($costEntry->storageItem?->qty_on_hand ?? 0) >= $costEntry->quantity;
                                                        @endphp
                                                        <form method="POST" action="{{ route('tech.tickets.cost-entries.pick', [$ticket, $costEntry]) }}">
                                                            @csrf
                                                            <button
                                                                type="submit"
                                                                class="btn btn-sm btn-outline-success"
                                                                @disabled(! $canPickCostEntry)
                                                                title="{{ $canPickCostEntry ? 'Pick item from stock and send it to Economy.' : 'Not enough on-hand stock to pick this item.' }}">
                                                                Pick
                                                            </button>
                                                        </form>
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-outline-primary ticket-edit-cost"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#ticketEditCostModal"
                                                            data-action="{{ route('tech.tickets.cost-entries.update', [$ticket, $costEntry]) }}"
                                                            data-quantity="{{ $costEntry->quantity }}"
                                                            data-invoice-text="{{ $costEntry->invoice_text }}"
                                                            data-note="{{ $costEntry->note }}"
                                                            data-item-label="{{ $costEntry->item_name }}{{ $costEntry->item_sku ? ' (' . $costEntry->item_sku . ')' : '' }}">
                                                            Edit
                                                        </button>
                                                    @endif
                                                </div>
                                                <div class="row g-2 small mb-2">
                                                    <div class="col-md-4">
                                                        <div class="border rounded bg-light px-2 py-1 h-100">
                                                            <div class="text-muted text-uppercase" style="font-size: .68rem;">Item</div>
                                                            <div class="fw-semibold text-truncate">{{ $costEntry->item_name }}</div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="border rounded bg-light px-2 py-1 h-100">
                                                            <div class="text-muted text-uppercase" style="font-size: .68rem;">Quantity</div>
                                                            <div class="fw-semibold">{{ $costEntry->quantity }}</div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="border rounded bg-light px-2 py-1 h-100">
                                                            <div class="text-muted text-uppercase" style="font-size: .68rem;">Status</div>
                                                            <div class="fw-semibold">{{ ucfirst($costEntry->status) }}</div>
                                                        </div>
                                                    </div>
                                                </div>
                                                @if(filled($costEntry->invoice_text))
                                                    <div class="small text-muted text-uppercase mb-1" style="font-size: .68rem;">Invoice text</div>
                                                    <div style="white-space: pre-wrap;">{{ $costEntry->invoice_text }}</div>
                                                @endif
                                                @if(filled($costEntry->note))
                                                    <div class="small text-muted text-uppercase mt-3 mb-1" style="font-size: .68rem;">Internal note</div>
                                                    <div style="white-space: pre-wrap;">{{ $costEntry->note }}</div>
                                                @endif
                                                <div class="d-flex flex-wrap gap-1 mt-3">
                                                    <span class="badge text-bg-light border">{{ $costEntry->storage_item_id ? 'Reservation' : 'Cost' }}: {{ ucfirst($costEntry->status) }}</span>
                                                    <span class="badge text-bg-light border">Billing: {{ ucfirst($costEntry->billing_status) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    @continue
                                @endif

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
                                                <div class="d-flex justify-content-end mb-2">
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-primary ticket-edit-time"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#ticketEditTimeModal"
                                                        data-action="{{ route('tech.tickets.time-entries.update', [$ticket, $timeEntry]) }}"
                                                        data-work-date="{{ $timeEntry->work_date?->toDateString() }}"
                                                        data-minutes="{{ $timeEntry->minutes }}"
                                                        data-rate-key="{{ $timeEntry->contract_item_time_rate_id ? 'contract:' . $timeEntry->contract_item_time_rate_id : ($timeEntry->time_rate_id ? 'global:' . $timeEntry->time_rate_id : '') }}"
                                                        data-invoice-text="{{ $timeEntry->invoice_text }}"
                                                        data-note="{{ $timeEntry->note }}">
                                                        Edit
                                                    </button>
                                                </div>
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
                                    // Only customer-authored messages are unread workflow items for technicians.
                                    $isCustomerAuthoredMessage = in_array($message->author_type, ['contact', 'portal_user'], true);
                                    $isUnreadMessage = $isCustomerAuthoredMessage && blank($message->read_at);
                                    $messageTypeLabel = $message->type === 'customer_reply' && $message->author_type === 'user'
                                        ? 'Technician reply'
                                        : ($message->type === 'status_update' ? 'Customer status update' : ucfirst(str_replace('_', ' ', $message->type)));
                                    $messageExcerpt = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', trim($message->body)), 120);
                                    $senderName = $isCustomerAuthoredMessage
                                        ? (iconv_mime_decode((string) ($message->metadata['from_name'] ?? ''), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $ticket->contact?->name ?? 'Customer')
                                        : ($message->author_type === 'system'
                                            ? 'Support'
                                            : ($message->author?->name ?? 'Technician'));
                                    $senderEmail = $message->author_type === 'contact'
                                        ? ($message->metadata['from_email'] ?? $ticket->contact?->email)
                                        : null;
                                    $participantLine = $isCustomerAuthoredMessage
                                        ? ($senderEmail ?: $senderName)
                                        : $senderName;
                                    $messageCollapseId = 'ticketMessageCollapse' . $message->id;
                                    $messageHeadingId = 'ticketMessageHeading' . $message->id;
                                    $isSolution = (bool) ($message->metadata['is_solution'] ?? false);
                                    $isPublicTechnicianReply = $message->author_type === 'user'
                                        && $message->type === 'customer_reply'
                                        && $message->visibility === 'public';
                                    $isInternalTechnicianNote = $allowInternalSolutionNotes
                                        && $message->author_type === 'user'
                                        && $message->type === 'internal_note'
                                        && $message->visibility === 'internal';
                                    $canMarkSolution = ($isPublicTechnicianReply || $isInternalTechnicianNote) && ! $isSolution;
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
                                                <!-- Technician replies and allowed internal notes can satisfy workflow solution requirements. -->
                                                <div class="d-flex justify-content-end mb-2">
                                                    <form method="POST" action="{{ route('tech.tickets.messages.solution', [$ticket, $message]) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-success">Mark as solution</button>
                                                    </form>
                                                </div>
                                            @endif
                                            @if (in_array($message->type, ['customer_reply', 'status_update'], true) && $latestEmailLog)
                                                <!-- Shows the latest outbound email status for this ticket message so technicians can see delivery problems without opening email logs. -->
                                                <div class="small {{ $latestEmailLog->level === 'error' ? 'text-danger' : 'text-success' }} mb-2">
                                                    {{ $latestEmailLog->level === 'error' ? 'Email failed' : 'Email sent' }}:
                                                    {{ $latestEmailLog->message }}
                                                    @if ($latestEmailLog->rfc_message_id)
                                                        <span class="text-muted">({{ $latestEmailLog->rfc_message_id }})</span>
                                                    @endif
                                                </div>
                                            @elseif (in_array($message->type, ['customer_reply', 'status_update'], true) && in_array($message->author_type, ['user', 'system'], true))
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
                                <input id="visibility" name="visibility" type="hidden" value="{{ in_array($selectedMessageType, ['internal_note', 'internal_solution'], true) ? 'internal' : 'public' }}">

                                <div class="row g-2 mb-3">
                                    <div class="col-md-6">
                                        <label for="type" class="form-label">Message type</label>
                                        <select id="type" name="type" class="form-select @error('type') is-invalid @enderror">
                                            @if ($canReplyToContact)
                                                <option value="customer_reply" @selected($selectedMessageType === 'customer_reply')>Reply to contact</option>
                                            @endif
                                            @if($canAddInternalNote)
                                                <option value="internal_note" @selected($selectedMessageType === 'internal_note')>Internal note</option>
                                                @if($allowInternalSolutionNotes)
                                                    <option value="internal_solution" @selected($selectedMessageType === 'internal_solution')>Internal solution</option>
                                                @endif
                                            @endif
                                        </select>
                                        @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div id="reply_intent_group" class="col-md-6 @if (in_array($selectedMessageType, ['internal_note', 'internal_solution'], true)) d-none @endif">
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

                                <div id="reply_recipient_group" class="row g-2 mb-3 @if (in_array($selectedMessageType, ['internal_note', 'internal_solution'], true)) d-none @endif">
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
                                        @if($ccContactSuggestions->isNotEmpty())
                                            <div id="cc_contact_suggestions" class="mt-2 d-none" aria-hidden="true">
                                                <div class="small text-muted fw-semibold mb-1">CC suggestions</div>
                                                @foreach($ccSuggestionGroups as $group => $suggestions)
                                                    <div class="small text-muted mt-2">{{ $group }}</div>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        @foreach($suggestions as $suggestion)
                                                            <button
                                                                type="button"
                                                                class="btn btn-sm btn-outline-secondary text-start"
                                                                data-cc-email="{{ $suggestion['email'] }}"
                                                                title="{{ $suggestion['name'] }} <{{ $suggestion['email'] }}>">
                                                                <span>{{ $suggestion['name'] }}</span>
                                                                @if($suggestion['site'])
                                                                    <span class="text-muted">({{ $suggestion['site'] }})</span>
                                                                @endif
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                @if($customerReplyBlockedByPortal)
                                    <div class="alert alert-secondary">
                                        Customer replies are disabled while this ticket is Unpublished. Internal notes are still available.
                                    </div>
                                @elseif (! $canReplyToContact && $allowInternalSolutionNotes)
                                    <div class="alert alert-warning">
                                        This ticket has no contact with an email address. Use an internal solution to document the fix without sending email.
                                    </div>
                                @elseif(! $canReplyToContact)
                                    <div class="alert alert-warning">
                                        This ticket has no contact with an email address. Internal solution notes are disabled by Ticket settings.
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
                                    <option value="{{ $rateOption['key'] }}" @selected($defaultTimeRateKey === $rateOption['key'])>
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

{{-- Time registration edit modal --}}
<div class="modal fade" id="ticketEditTimeModal" tabindex="-1" aria-labelledby="ticketEditTimeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form id="ticketEditTimeForm" method="POST" action="#">
                @csrf
                @method('PATCH')

                <div class="modal-header">
                    <h2 class="modal-title h6" id="ticketEditTimeModalLabel">Edit time</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="edit_time_work_date" class="form-label">Date</label>
                            <input id="edit_time_work_date" name="work_date" type="date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_time_minutes" class="form-label">Minutes</label>
                            <input id="edit_time_minutes" name="minutes" type="number" min="1" max="1440" step="1" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label for="edit_time_rate_key" class="form-label">Time rate</label>
                            <select id="edit_time_rate_key" name="rate_key" class="form-select" required>
                                <option value="">Select rate</option>
                                @foreach($timeRateOptions as $rateOption)
                                    <option value="{{ $rateOption['key'] }}">{{ $rateOption['label'] }} - {{ $rateOption['description'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="edit_time_invoice_text" class="form-label">Invoice text</label>
                            <textarea id="edit_time_invoice_text" name="invoice_text" rows="4" class="form-control" required></textarea>
                        </div>
                        <div class="col-12">
                            <label for="edit_time_note" class="form-label">Internal note</label>
                            <textarea id="edit_time_note" name="note" rows="2" class="form-control"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update time</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Storage cost reservation modal --}}
<div class="modal fade" id="ticketAddCostModal" tabindex="-1" aria-labelledby="ticketAddCostModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="{{ route('tech.tickets.cost-entries.store', $ticket) }}">
                @csrf

                <div class="modal-header">
                    <h2 class="modal-title h6" id="ticketAddCostModalLabel">Add cost</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Cost type</label>
                            <div class="btn-group w-100" role="group" aria-label="Cost type">
                                <input type="radio" class="btn-check" name="cost_mode" id="cost_mode_storage" value="storage" autocomplete="off" @checked(old('cost_mode', 'storage') === 'storage')>
                                <label class="btn btn-outline-secondary" for="cost_mode_storage">Storage item</label>

                                <input type="radio" class="btn-check" name="cost_mode" id="cost_mode_manual" value="manual" autocomplete="off" @checked(old('cost_mode') === 'manual')>
                                <label class="btn btn-outline-secondary" for="cost_mode_manual">Manual cost</label>
                            </div>
                            @error('cost_mode')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12" data-cost-storage-fields>
                            <label for="cost_storage_item_search" class="form-label">Storage item</label>
                            <input id="cost_storage_item_id" name="storage_item_id" type="hidden" value="{{ old('storage_item_id') }}">
                            <input
                                id="cost_storage_item_search"
                                type="search"
                                class="form-control @error('storage_item_id') is-invalid @enderror"
                                placeholder="Start typing item name, SKU, or location"
                                autocomplete="off">
                            @error('storage_item_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div id="cost_storage_item_suggestions" class="list-group mt-2" style="max-height: 14rem; overflow-y: auto;">
                                @foreach($storageItems as $storageItem)
                                    @php
                                        $canSelectStorageItem = $storageItem['available'] > 0 || $storageItem['can_be_ordered'];
                                    @endphp
                                    <button
                                        type="button"
                                        class="list-group-item list-group-item-action py-2 cost-storage-suggestion @unless($canSelectStorageItem) disabled @endunless"
                                        data-id="{{ $storageItem['id'] }}"
                                        data-label="{{ $storageItem['label'] }}"
                                        data-price="{{ $storageItem['sale_price'] }}"
                                        data-invoice-text="{{ $storageItem['short_description'] }}"
                                        data-search="{{ \Illuminate\Support\Str::lower($storageItem['label'] . ' ' . $storageItem['location']) }}"
                                        @disabled(! $canSelectStorageItem)>
                                        <span class="d-flex justify-content-between gap-2">
                                            <span class="fw-semibold text-truncate">{{ $storageItem['label'] }}</span>
                                            <span class="small text-muted flex-shrink-0">
                                                Available: {{ $storageItem['available'] }}
                                                @if($storageItem['available'] < 1 && $storageItem['can_be_ordered'])
                                                    &middot; order needed
                                                @elseif($storageItem['available'] < 1)
                                                    &middot; cannot order
                                                @endif
                                            </span>
                                        </span>
                                        @if(filled($storageItem['location']))
                                            <span class="small text-muted d-block text-truncate">{{ $storageItem['location'] }}</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                            <div id="cost_storage_item_no_results" class="text-muted small mt-2 d-none">No matching storage items.</div>
                        </div>

                        <div class="col-md-6 d-none" data-cost-manual-fields>
                            <label for="cost_item_name" class="form-label">Cost name</label>
                            <input id="cost_item_name" name="item_name" type="text" class="form-control @error('item_name') is-invalid @enderror" value="{{ old('item_name') }}">
                            @error('item_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3 d-none" data-cost-manual-fields>
                            <label for="cost_unit_price_ex_vat" class="form-label">Unit price ex VAT</label>
                            <input id="cost_unit_price_ex_vat" name="unit_price_ex_vat" type="number" min="0" step="0.01" class="form-control @error('unit_price_ex_vat') is-invalid @enderror" value="{{ old('unit_price_ex_vat') }}">
                            @error('unit_price_ex_vat')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3 d-none" data-cost-manual-fields>
                            <label for="cost_currency" class="form-label">Currency</label>
                            <input id="cost_currency" name="currency" type="text" maxlength="3" class="form-control @error('currency') is-invalid @enderror" value="{{ old('currency', 'NOK') }}">
                            @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4" data-cost-storage-fields>
                            <label for="cost_unit_price" class="form-label">Unit price</label>
                            <input id="cost_unit_price" type="text" class="form-control" value="-" readonly>
                        </div>
                        <div class="col-md-4">
                            <label for="cost_quantity" class="form-label">Quantity</label>
                            <input id="cost_quantity" name="quantity" type="number" min="1" step="1" class="form-control @error('quantity') is-invalid @enderror" value="{{ old('quantity', 1) }}" required>
                            @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4" data-cost-storage-fields>
                            <label for="cost_total_price" class="form-label">Total</label>
                            <input id="cost_total_price" type="text" class="form-control" value="-" readonly>
                        </div>
                        <div class="col-12">
                            <label for="cost_invoice_text" class="form-label">Invoice text</label>
                            <textarea id="cost_invoice_text" name="invoice_text" rows="3" class="form-control @error('invoice_text') is-invalid @enderror">{{ old('invoice_text') }}</textarea>
                            @error('invoice_text')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="cost_note" class="form-label">Internal note</label>
                            <textarea id="cost_note" name="note" rows="2" class="form-control @error('note') is-invalid @enderror">{{ old('note') }}</textarea>
                            @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save cost</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Storage cost edit modal --}}
<div class="modal fade" id="ticketEditCostModal" tabindex="-1" aria-labelledby="ticketEditCostModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form id="ticketEditCostForm" method="POST" action="#">
                @csrf
                @method('PATCH')

                <div class="modal-header">
                    <h2 class="modal-title h6" id="ticketEditCostModalLabel">Edit cost</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <div class="small text-muted text-uppercase" style="font-size: .68rem;">Item</div>
                        <div id="editCostItemLabel" class="fw-semibold"></div>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label for="edit_cost_quantity" class="form-label">Quantity</label>
                            <input id="edit_cost_quantity" name="quantity" type="number" min="1" step="1" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label for="edit_cost_invoice_text" class="form-label">Invoice text</label>
                            <textarea id="edit_cost_invoice_text" name="invoice_text" rows="3" class="form-control"></textarea>
                        </div>
                        <div class="col-12">
                            <label for="edit_cost_note" class="form-label">Internal note</label>
                            <textarea id="edit_cost_note" name="note" rows="2" class="form-control"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update reservation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-ticket-workflow-step]').forEach(function (step) {
            if (window.bootstrap?.Popover) {
                bootstrap.Popover.getOrCreateInstance(step, { container: 'body' });
            }
        });

        const type = document.getElementById('type');
        const visibility = document.getElementById('visibility');
        const replyRecipientGroup = document.getElementById('reply_recipient_group');
        const replyIntentGroup = document.getElementById('reply_intent_group');
        const notifyTechnicianGroup = document.getElementById('notify_technician_group');
        const replyShortcut = document.getElementById('ticketReplyShortcut');
        const composer = document.getElementById('ticketComposerCollapse');
        const body = document.getElementById('body');
        const ccInput = document.getElementById('cc');
        const addTimeModal = document.getElementById('ticketAddTimeModal');
        const shouldShowAddTimeModal = @json((bool) $showAddTimeModal);
        const timeAiDraft = document.getElementById('ticketTimeAiDraft');
        const timeInvoiceText = document.getElementById('time_invoice_text');
        const timeRateSelect = document.getElementById('time_rate_key');
        const timeAiDraftStatus = document.getElementById('ticketTimeAiDraftStatus');
        const timeMinutes = document.getElementById('time_minutes');
        const timeWorkDate = document.getElementById('time_work_date');
        const editTimeForm = document.getElementById('ticketEditTimeForm');
        const editTimeWorkDate = document.getElementById('edit_time_work_date');
        const editTimeMinutes = document.getElementById('edit_time_minutes');
        const editTimeRateKey = document.getElementById('edit_time_rate_key');
        const editTimeInvoiceText = document.getElementById('edit_time_invoice_text');
        const editTimeNote = document.getElementById('edit_time_note');
        const editCostForm = document.getElementById('ticketEditCostForm');
        const editCostItemLabel = document.getElementById('editCostItemLabel');
        const editCostQuantity = document.getElementById('edit_cost_quantity');
        const editCostInvoiceText = document.getElementById('edit_cost_invoice_text');
        const editCostNote = document.getElementById('edit_cost_note');
        const costItemSearch = document.getElementById('cost_storage_item_search');
        const costItemId = document.getElementById('cost_storage_item_id');
        const costItemSuggestions = document.getElementById('cost_storage_item_suggestions');
        const costItemNoResults = document.getElementById('cost_storage_item_no_results');
        const costQuantity = document.getElementById('cost_quantity');
        const costUnitPrice = document.getElementById('cost_unit_price');
        const costTotalPrice = document.getElementById('cost_total_price');
        const costInvoiceText = document.getElementById('cost_invoice_text');
        const costModeInputs = Array.from(document.querySelectorAll('input[name="cost_mode"]'));
        const costStorageFields = Array.from(document.querySelectorAll('[data-cost-storage-fields]'));
        const costManualFields = Array.from(document.querySelectorAll('[data-cost-manual-fields]'));
        let selectedCostItem = null;
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

            const isInternal = type.value === 'internal_note' || type.value === 'internal_solution';
            const isInternalSolution = type.value === 'internal_solution';

            visibility.value = isInternal ? 'internal' : 'public';
            replyRecipientGroup.classList.toggle('d-none', isInternal);
            replyIntentGroup.classList.toggle('d-none', isInternal);
            notifyTechnicianGroup.classList.toggle('d-none', ! isInternal || isInternalSolution);
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

        document.querySelectorAll('[data-cc-email]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (! ccInput) {
                    return;
                }

                const email = this.dataset.ccEmail;
                const current = ccInput.value
                    .split(/[;,]+/)
                    .map((value) => value.trim())
                    .filter(Boolean);
                const exists = current.some((value) => value.toLowerCase() === email.toLowerCase());

                if (! exists) {
                    current.push(email);
                }

                ccInput.value = current.join(', ');
                ccInput.focus();
            });
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
                const actionAllowed = stopwatchStart.dataset.actionAllowed === '1';
                stopwatchStart.disabled = ! actionAllowed || stopwatch.running;
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

        stopwatchStart?.addEventListener('click', async function () {
            if (this.dataset.actionAllowed !== '1' || ! this.dataset.startUrl) {
                return;
            }

            this.disabled = true;

            try {
                const response = await fetch(this.dataset.startUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': @json(csrf_token()),
                    },
                    body: JSON.stringify({}),
                });
                const payload = await response.json().catch(() => ({}));

                if (! response.ok) {
                    const validationMessage = Object.values(payload.errors || {}).flat()[0];
                    throw new Error(validationMessage || payload.message || 'The timer could not be started.');
                }

                stopwatch = {
                    elapsedMs: 0,
                    startedAt: Date.now(),
                    running: true,
                };
                saveStopwatch();
                syncStopwatchUi();

                if (payload.data?.transitioned) {
                    window.location.reload();
                }
            } catch (error) {
                window.alert(error.message || 'The timer could not be started.');
                syncStopwatchUi();
            }
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

        const formatMoney = function (value) {
            if (Number.isNaN(value)) {
                return '-';
            }

            return new Intl.NumberFormat('nb-NO', {
                style: 'currency',
                currency: 'NOK',
            }).format(value);
        };

        const setSelectedCostItem = function (button, fillInvoiceText = true) {
            if (! button) {
                selectedCostItem = null;
                if (costItemId) {
                    costItemId.value = '';
                }
                return;
            }

            selectedCostItem = {
                id: button.dataset.id,
                label: button.dataset.label,
                price: button.dataset.price,
                invoiceText: button.dataset.invoiceText || '',
            };

            if (costItemId) {
                costItemId.value = selectedCostItem.id || '';
            }

            if (costItemSearch) {
                costItemSearch.value = selectedCostItem.label || '';
            }

            document.querySelectorAll('.cost-storage-suggestion').forEach(function (suggestion) {
                suggestion.classList.toggle('active', suggestion === button);
            });

            syncCostPreview(fillInvoiceText);
        };

        const syncCostPreview = function (fillInvoiceText = false) {
            const price = Number(selectedCostItem?.price || NaN);
            const quantity = Number(costQuantity?.value || 0);

            if (costUnitPrice) {
                costUnitPrice.value = formatMoney(price);
            }

            if (costTotalPrice) {
                costTotalPrice.value = formatMoney(price * quantity);
            }

            if (fillInvoiceText && costInvoiceText && ! costInvoiceText.value.trim()) {
                costInvoiceText.value = selectedCostItem?.invoiceText || '';
            }
        };

        const syncCostMode = function () {
            const mode = costModeInputs.find((input) => input.checked)?.value || 'storage';
            const isManual = mode === 'manual';

            costStorageFields.forEach((element) => element.classList.toggle('d-none', isManual));
            costManualFields.forEach((element) => element.classList.toggle('d-none', ! isManual));
        };

        const filterCostSuggestions = function () {
            const search = costItemSearch?.value.trim().toLowerCase() || '';
            let visibleCount = 0;

            document.querySelectorAll('.cost-storage-suggestion').forEach(function (button) {
                const matches = search === '' || (button.dataset.search || '').includes(search);
                button.classList.toggle('d-none', ! matches);

                if (matches) {
                    visibleCount++;
                }
            });

            costItemNoResults?.classList.toggle('d-none', visibleCount > 0);
        };

        costItemSearch?.addEventListener('input', function () {
            if (! selectedCostItem || costItemSearch.value !== selectedCostItem.label) {
                selectedCostItem = null;

                if (costItemId) {
                    costItemId.value = '';
                }

                document.querySelectorAll('.cost-storage-suggestion.active').forEach(function (button) {
                    button.classList.remove('active');
                });
            }

            filterCostSuggestions();
            syncCostPreview(false);
        });

        costQuantity?.addEventListener('input', function () {
            syncCostPreview(false);
        });

        costModeInputs.forEach(function (input) {
            input.addEventListener('change', syncCostMode);
        });

        document.querySelectorAll('.cost-storage-suggestion').forEach(function (button) {
            button.addEventListener('click', function () {
                if (button.disabled || button.classList.contains('disabled')) {
                    return;
                }

                setSelectedCostItem(button, true);
                filterCostSuggestions();
            });
        });

        if (costItemId?.value) {
            setSelectedCostItem(document.querySelector(`.cost-storage-suggestion[data-id="${costItemId.value}"]`), false);
        }

        filterCostSuggestions();
        syncCostPreview(false);
        syncCostMode();

        document.querySelectorAll('.ticket-edit-cost').forEach(function (button) {
            button.addEventListener('click', function () {
                if (editCostForm) {
                    editCostForm.action = button.dataset.action || '#';
                }

                if (editCostItemLabel) {
                    editCostItemLabel.textContent = button.dataset.itemLabel || '';
                }

                if (editCostQuantity) {
                    editCostQuantity.value = button.dataset.quantity || 1;
                }

                if (editCostInvoiceText) {
                    editCostInvoiceText.value = button.dataset.invoiceText || '';
                }

                if (editCostNote) {
                    editCostNote.value = button.dataset.note || '';
                }
            });
        });

        document.querySelectorAll('.ticket-edit-time').forEach(function (button) {
            button.addEventListener('click', function () {
                if (editTimeForm) {
                    editTimeForm.action = button.dataset.action || '#';
                }

                if (editTimeWorkDate) {
                    editTimeWorkDate.value = button.dataset.workDate || new Date().toISOString().slice(0, 10);
                }

                if (editTimeMinutes) {
                    editTimeMinutes.value = button.dataset.minutes || 1;
                }

                if (editTimeRateKey) {
                    editTimeRateKey.value = button.dataset.rateKey || '';
                }

                if (editTimeInvoiceText) {
                    editTimeInvoiceText.value = button.dataset.invoiceText || '';
                }

                if (editTimeNote) {
                    editTimeNote.value = button.dataset.note || '';
                }
            });
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
                    <!-- Timer start is audited by the server; elapsed draft time stays local until Add time is saved. -->
                    <div class="text-center border rounded bg-light px-2 py-3">
                        <div id="ticketStopwatchDisplay" class="fw-semibold font-monospace" style="font-size: 1.75rem;">00:00:00</div>
                        <div id="ticketStopwatchState" class="small text-muted mt-1">Not running</div>
                    </div>
                    <div id="ticketStopwatchStartGroup" class="d-grid gap-2 mt-3">
                        @php
                            $startTimerDecision = $actionDecision('start_timer');
                        @endphp
                        @if($startTimerDecision['visible'])
                            <button
                                id="ticketStopwatchStart"
                                type="button"
                                class="btn btn-sm btn-primary"
                                data-start-url="{{ route('tech.tickets.timer.start', $ticket) }}"
                                data-action-allowed="{{ $startTimerDecision['allowed'] ? '1' : '0' }}"
                                @disabled(! $startTimerDecision['allowed'])
                                title="{{ $startTimerDecision['reason'] }}">
                                <i class="bi bi-play-fill" aria-hidden="true"></i>
                                Start
                            </button>
                        @endif
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

        @php
            $ticketTasks = $ticket->tasks->sortByDesc('updated_at')->values();
            $hasTicketTasks = $ticketTasks->isNotEmpty();
        @endphp

        <div class="accordion-item border rounded mb-2 overflow-hidden">
            <h2 class="accordion-header" id="ticketTasksHeading">
                <button
                    class="accordion-button {{ $hasTicketTasks ? '' : 'collapsed' }} py-2 px-3"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ticketTasksCollapse"
                    aria-expanded="{{ $hasTicketTasks ? 'true' : 'false' }}"
                    aria-controls="ticketTasksCollapse">
                    <span class="d-flex align-items-center gap-2">
                        <i class="bi bi-list-task" aria-hidden="true"></i>
                        <span>Tasks</span>
                        <span class="badge text-bg-secondary">{{ $ticket->tasks->count() }}</span>
                    </span>
                </button>
            </h2>
            <div
                id="ticketTasksCollapse"
                class="accordion-collapse collapse {{ $hasTicketTasks ? 'show' : '' }}"
                aria-labelledby="ticketTasksHeading"
                data-bs-parent="#ticketRightbarAccordion">
                <div class="accordion-body p-3">
                    <div class="d-grid mb-3">
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#ticketTaskQuickCreateModal">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                            New Task
                        </button>
                    </div>

                    <div class="list-group list-group-flush">
                        @forelse($ticketTasks->take(6) as $task)
                            <button type="button" class="list-group-item list-group-item-action px-0 py-2 text-start" data-bs-toggle="modal" data-bs-target="#ticketTaskQuickViewModal{{ $task->id }}">
                                <div class="d-flex justify-content-between gap-2">
                                    <span class="fw-semibold small text-truncate">{{ $task->title }}</span>
                                    <span class="badge {{ $task->status?->is_done ? 'text-bg-success' : 'text-bg-light border' }}">{{ $task->status?->name ?? 'Open' }}</span>
                                </div>
                                <div class="small text-muted">
                                    {{ $task->assignee?->name ?? 'Unassigned' }}
                                    @if($task->due_at)
                                        <span class="ms-1">Due {{ $task->due_at->format('Y-m-d') }}</span>
                                    @endif
                                </div>
                            </button>
                        @empty
                            <p class="text-muted small mb-0">No tasks yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="accordion-item border rounded mb-2 overflow-hidden">
            <h2 class="accordion-header" id="ticketNextStepHeading">
                <button
                    class="accordion-button collapsed py-2 px-3"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ticketNextStepCollapse"
                    aria-expanded="false"
                    aria-controls="ticketNextStepCollapse">
                    <span class="d-flex align-items-center gap-2">
                        <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                        <span>Next step</span>
                        <span class="badge text-bg-light border">{{ $workflowCurrentState['name'] ?? $ticket->status?->name ?? 'Current' }}</span>
                    </span>
                </button>
            </h2>
            <div
                id="ticketNextStepCollapse"
                class="accordion-collapse collapse"
                aria-labelledby="ticketNextStepHeading"
                data-bs-parent="#ticketRightbarAccordion">
                <div class="accordion-body p-3">
                    @php
                        $changeStateDecision = $ticketActionDecisions['change_status'] ?? ['visible' => true, 'allowed' => true, 'reason' => null];
                        $escalateDecision = $ticketActionDecisions['escalate'] ?? ['visible' => true, 'allowed' => true, 'reason' => null];
                        $nonTerminalTransitions = collect($workflowTransitionDecisions)->reject(fn ($decision) => (bool) data_get($decision, 'target_state.is_terminal'));
                    @endphp

                    <div class="d-grid gap-2">
                        @if($changeStateDecision['visible'])
                            @forelse($nonTerminalTransitions as $transition)
                                @php
                                    $transitionAllowed = $changeStateDecision['allowed'] && ($transition['allowed'] ?? false);
                                    $transitionReason = $changeStateDecision['reason'] ?: ($transition['disabled_reason'] ?? null);
                                @endphp
                                <form method="POST" action="{{ route('tech.tickets.workflow-v3.transition', [$ticket, $transition['transition_key']]) }}">
                                    @csrf
                                    <input type="hidden" name="idempotency_key" value="web-{{ $ticket->id }}-{{ $transition['transition_key'] }}-{{ now()->format('YmdHi') }}">
                                    <button
                                        type="submit"
                                        class="btn btn-sm {{ $transitionAllowed ? 'btn-outline-primary' : 'btn-outline-secondary' }} w-100 text-start"
                                        @disabled(! $transitionAllowed)
                                        title="{{ $transitionReason }}"
                                    >
                                        <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                                        {{ $transition['label'] }}
                                        <span class="text-muted">to {{ data_get($transition, 'target_state.name') }}</span>
                                    </button>
                                    @if(! $transitionAllowed && $transitionReason)
                                        <div class="small text-danger mt-1">{{ $transitionReason }}</div>
                                    @endif
                                </form>
                            @empty
                                <p class="text-muted small mb-0">No manual next step is configured from this state.</p>
                            @endforelse
                        @endif
                    </div>

                    @if($escalateDecision['visible'] && collect($workflowEscalationDecisions)->isNotEmpty())
                        <hr>
                        <div class="small fw-semibold mb-2">Escalate Ticket</div>
                        <div class="d-grid gap-2">
                            @foreach($workflowEscalationDecisions as $escalation)
                                @php
                                    $escalationAllowed = $escalateDecision['allowed'] && ($escalation['allowed'] ?? false);
                                    $escalationReason = $escalateDecision['reason'] ?: ($escalation['disabled_reason'] ?? null);
                                @endphp
                                <form method="POST" action="{{ route('tech.tickets.workflow-v3.escalate', [$ticket, $escalation['path_key']]) }}" class="border rounded p-2">
                                    @csrf
                                    <div class="small fw-semibold">{{ $escalation['label'] ?? 'Escalate to another workflow' }}</div>
                                    <div class="small text-muted mb-2">{{ ($escalation['mode'] ?? 'optional') === 'required' ? 'Required before protected actions' : 'Optional technician choice' }}</div>
                                    @if(($escalation['assignment_strategy'] ?? '') === 'manual')
                                        <select name="owner_id" class="form-select form-select-sm mb-2" @disabled(! $escalationAllowed)>
                                            <option value="">Leave unassigned</option>
                                            @foreach($technicians as $technician)
                                                <option value="{{ $technician->id }}">{{ $technician->name }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <input name="reason" class="form-control form-control-sm mb-2" placeholder="Reason">
                                    <button class="btn btn-sm btn-outline-warning w-100" @disabled(! $escalationAllowed) title="{{ $escalationReason }}">Escalate</button>
                                    @if(! $escalationAllowed && $escalationReason)
                                        <div class="small text-danger mt-1">{{ $escalationReason }}</div>
                                    @endif
                                </form>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Customer section: quick access to client name, number, domain, contact, and site --}}
        <div class="accordion-item border rounded mb-2 overflow-hidden">
            <h2 class="accordion-header" id="ticketCustomerHeading">
                <button
                    class="accordion-button py-2 px-3"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ticketCustomerCollapse"
                    aria-expanded="true"
                    aria-controls="ticketCustomerCollapse">
                    <span class="d-flex align-items-center gap-2">
                        <i class="bi bi-person-vcard" aria-hidden="true"></i>
                        <span>Customer</span>
                        @if($ticket->client)
                            <span class="badge text-bg-light border text-truncate" style="max-width: 12em;">{{ $ticket->client->name }}</span>
                        @endif
                    </span>
                </button>
            </h2>
            <div
                id="ticketCustomerCollapse"
                class="accordion-collapse collapse show"
                aria-labelledby="ticketCustomerHeading"
                data-bs-parent="#ticketRightbarAccordion">
                <div class="accordion-body p-3">
                    @if($ticket->client)
                        @php
                            $ticketContact = $ticket->contact;
                            $contactRecord = $ticketContact?->contact;
                            $customerSite = $ticket->site ?: $ticketContact?->site;
                            $clientWebsite = trim((string) $ticket->client->website);
                            $clientWebsiteUrl = $clientWebsite !== '' && ! preg_match('#^https?://#i', $clientWebsite)
                                ? 'https://' . $clientWebsite
                                : $clientWebsite;

                            $contactEmailRows = collect();
                            if (filled($ticketContact?->email)) {
                                $contactEmailRows->push([
                                    'email' => $ticketContact->email,
                                    'label' => 'Ticket contact',
                                ]);
                            }
                            foreach (($contactRecord?->emails ?? collect())->sortByDesc('is_primary') as $email) {
                                if (filled($email->email)) {
                                    $contactEmailRows->push([
                                        'email' => $email->email,
                                        'label' => $email->label ?: ($email->is_primary ? 'Primary' : 'Email'),
                                    ]);
                                }
                            }
                            $contactEmailRows = $contactEmailRows
                                ->unique(fn ($row) => strtolower($row['email']))
                                ->values();

                            $contactPhoneRows = collect();
                            if (filled($ticketContact?->phone)) {
                                $contactPhoneRows->push([
                                    'phone' => $ticketContact->phone,
                                    'label' => 'Ticket contact',
                                ]);
                            }
                            foreach (($contactRecord?->phones ?? collect())->sortByDesc('is_primary') as $phone) {
                                if (filled($phone->phone)) {
                                    $contactPhoneRows->push([
                                        'phone' => $phone->phone,
                                        'label' => $phone->label ?: ($phone->is_primary ? 'Primary' : 'Phone'),
                                    ]);
                                }
                            }
                            $contactPhoneRows = $contactPhoneRows
                                ->unique(fn ($row) => preg_replace('/\D+/', '', $row['phone']) ?: strtolower($row['phone']))
                                ->values();
                            $primaryPhone = $contactPhoneRows->first();
                            $primaryPhoneHref = $primaryPhone
                                ? preg_replace('/(?!^\+)[^\d]/', '', $primaryPhone['phone'])
                                : null;
                        @endphp
                        <div class="small">
                            {{-- Client name and number --}}
                            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                                <div class="min-w-0">
                                    <div class="text-muted text-uppercase mb-1" style="font-size: .68rem;">Client</div>
                                    <a href="{{ route('tech.clients.show', $ticket->client) }}" class="fw-semibold text-decoration-none text-truncate d-block">{{ $ticket->client->name }}</a>
                                    @if($ticket->client->client_number)
                                        <div class="text-muted">{{ $ticket->client->client_number }}</div>
                                    @endif
                                </div>
                                <a href="{{ route('tech.clients.show', $ticket->client) }}" class="btn btn-sm btn-outline-secondary flex-shrink-0" title="Open client">
                                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                </a>
                            </div>
                            @if($clientWebsite !== '')
                                <div class="text-truncate mb-1">
                                    <i class="bi bi-globe small" aria-hidden="true"></i>
                                    <a href="{{ $clientWebsiteUrl }}" target="_blank" rel="noopener" class="text-decoration-none">{{ preg_replace('#^https?://(www\.)?#', '', $clientWebsite) }}</a>
                                </div>
                            @endif
                            @if($ticket->client->billing_email)
                                <div class="text-truncate mb-1">
                                    <i class="bi bi-envelope-at small" aria-hidden="true"></i>
                                    <a href="mailto:{{ $ticket->client->billing_email }}" class="text-decoration-none">{{ $ticket->client->billing_email }}</a>
                                </div>
                            @endif

                            {{-- Contact person --}}
                            @if($ticketContact)
                                <div class="border-top mt-2 pt-2">
                                    <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                                        <div class="min-w-0">
                                            <div class="text-muted text-uppercase mb-1" style="font-size: .68rem;">Contact</div>
                                            <a href="{{ route('tech.clients.user.show', $ticketContact) }}" class="fw-semibold text-decoration-none text-truncate d-block">{{ $ticketContact->name }}</a>
                                            @if($ticketContact->role)
                                                <div class="text-muted text-truncate">{{ $ticketContact->role }}</div>
                                            @endif
                                        </div>
                                        @if($primaryPhoneHref)
                                            <a href="tel:{{ $primaryPhoneHref }}" class="btn btn-sm btn-outline-primary flex-shrink-0">
                                                <i class="bi bi-telephone" aria-hidden="true"></i>
                                                Call
                                            </a>
                                        @endif
                                    </div>

                                    @if($contactPhoneRows->isNotEmpty())
                                        <div class="d-grid gap-1 mb-2">
                                            @foreach($contactPhoneRows as $phoneRow)
                                                @php
                                                    $phoneHref = preg_replace('/(?!^\+)[^\d]/', '', $phoneRow['phone']);
                                                @endphp
                                                <a href="tel:{{ $phoneHref }}" class="d-flex align-items-center gap-2 text-decoration-none">
                                                    <i class="bi bi-telephone small" aria-hidden="true"></i>
                                                    <span class="text-truncate">{{ $phoneRow['phone'] }}</span>
                                                    <span class="badge text-bg-light border ms-auto">{{ $phoneRow['label'] }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="text-muted mb-2">No phone number registered.</div>
                                    @endif

                                    @if($contactEmailRows->isNotEmpty())
                                        <div class="d-grid gap-1">
                                            @foreach($contactEmailRows as $emailRow)
                                                <a href="mailto:{{ $emailRow['email'] }}" class="d-flex align-items-center gap-2 text-decoration-none">
                                                    <i class="bi bi-envelope small" aria-hidden="true"></i>
                                                    <span class="text-truncate">{{ $emailRow['email'] }}</span>
                                                    <span class="badge text-bg-light border ms-auto">{{ $emailRow['label'] }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif

                            {{-- Site --}}
                            @if($customerSite)
                                <div class="border-top mt-2 pt-2">
                                    <div class="d-flex align-items-start justify-content-between gap-2">
                                        <div class="min-w-0">
                                            <div class="text-muted text-uppercase mb-1" style="font-size: .68rem;">Site</div>
                                            <a href="{{ route('tech.clients.sites.show', $customerSite) }}" class="fw-semibold text-decoration-none text-truncate d-block">{{ $customerSite->name }}</a>
                                        </div>
                                        <a href="{{ route('tech.clients.sites.show', $customerSite) }}" class="btn btn-sm btn-outline-secondary flex-shrink-0" title="Open site">
                                            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                    @if($customerSite->address)
                                        <div class="mt-1">{{ $customerSite->address }}</div>
                                    @endif
                                    @if($customerSite->zip || $customerSite->city)
                                        <div>{{ trim(($customerSite->zip ?? '') . ' ' . ($customerSite->city ?? '')) }}</div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @elseif($ticket->workContext?->isInternal())
                        <p class="text-muted small mb-0">Internal work. No customer context is attached.</p>
                    @else
                        <p class="text-muted small mb-0">No client assigned.</p>
                    @endif
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
            @php
                $documentationRequests = $ticket->events
                    ->where('type', 'documentation_requested')
                    ->sortByDesc('created_at');
            @endphp
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
                        @if($documentationRequests->isNotEmpty())
                            <span class="badge text-bg-warning">{{ $documentationRequests->count() }} follow-up</span>
                        @endif
                    </span>
                </button>
            </h2>
            <div
                id="ticketKnowledgeCollapse"
                class="accordion-collapse collapse"
                aria-labelledby="ticketKnowledgeHeading"
                data-bs-parent="#ticketRightbarAccordion">
                <div class="accordion-body p-3">
                    <!-- Documentation follow-up is a lightweight workflow marker until Knowledge drafts are implemented. -->
                    <form method="POST" action="{{ route('tech.tickets.documentation-request', $ticket) }}" class="border rounded bg-light p-2 mb-3">
                        @csrf
                        <label for="documentation_reason" class="form-label small fw-semibold">Documentation follow-up</label>
                        <textarea id="documentation_reason" name="reason" rows="2" class="form-control form-control-sm mb-2" placeholder="What should be documented?"></textarea>
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-journal-plus" aria-hidden="true"></i>
                            Request documentation
                        </button>
                    </form>

                    @if($documentationRequests->isNotEmpty())
                        <div class="mb-3">
                            <div class="text-muted text-uppercase mb-1" style="font-size: .68rem;">Follow-ups</div>
                            @foreach($documentationRequests->take(3) as $event)
                                <div class="border rounded px-2 py-1 mb-1 small">
                                    <div class="fw-semibold">{{ $event->created_at?->format('Y-m-d H:i') }}</div>
                                    <div class="text-muted">{{ $event->message }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @forelse ($knowledgeSuggestions as $article)
                        <!-- Suggested articles are ranked from ticket context and opened separately so the ticket workflow stays in place. -->
                        <a
                            href="{{ route('tech.knowledge.show', $article) }}"
                            target="_blank"
                            rel="noopener"
                            class="d-block text-decoration-none text border rounded bg-light px-2 py-2 mb-2">
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
                        $firstResponseClass = $ticket->first_response_due_at?->isPast() && ! $ticket->first_responded_at ? 'text-danger' : 'text';
                        $resolveClass = $ticket->resolve_due_at?->isPast() && ! $ticket->resolved_at ? 'text-danger' : 'text';
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

    @include('task::components.quick-create-modal', [
        'modalId' => 'ticketTaskQuickCreateModal',
        'ownerModel' => $ticket,
        'assignees' => $technicians,
        'defaultAssigneeId' => $ticket->owner_id,
        'returnTo' => route('tech.tickets.show', $ticket),
        'timeRateOptions' => $timeRateOptions,
    ])

    @foreach($ticketTasks as $task)
        @include('task::components.quick-view-modal', [
            'modalId' => 'ticketTaskQuickViewModal'.$task->id,
            'task' => $task,
            'assignees' => $technicians,
            'timeRateOptions' => $timeRateOptions,
        ])
    @endforeach
@endsection
