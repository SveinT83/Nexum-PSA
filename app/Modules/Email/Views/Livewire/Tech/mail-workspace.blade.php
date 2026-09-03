<div class="mail-workspace-root"
     x-data="{ liveMode: 'off' }"
     @if($liveEnabled)
         x-init="window.EmailMailLive?.init({{ auth()->id() }}, (force) => $wire.catchUpInvalidation(force))"
         @email-mail-invalidated.window="$wire.handleEmailProjectionInvalidated($event.detail.payload)"
         @email-mail-live-mode.window="liveMode = $event.detail.mode"
     @else
         wire:poll.60s
     @endif>
    {{-- Live transport status: visible only during the intentional polling fallback. --}}
    @if($liveEnabled)
        <div x-cloak
             x-show="liveMode === 'poll'"
             class="alert alert-warning py-1 px-2 mb-2 small"
             role="status">
            Live updates are unavailable. Mail is checking for changes automatically.
        </div>
    @endif

    <style>
        .mail-workspace-grid {
            display: grid;
            grid-template-columns: minmax(340px, .95fr) minmax(380px, 1.05fr);
            gap: .75rem;
        }

        .mail-pane {
            min-width: 0;
            border: 1px solid var(--bs-border-color);
            border-radius: .5rem;
            background: var(--bs-body-bg);
        }

        .mail-scroll {
            overflow: auto;
        }

        .mail-reader-body {
            overflow: auto;
        }

        .mail-list-toolbar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(9.5rem, .5fr);
            gap: .5rem;
        }

        .mail-list-toolbar .input-group,
        .mail-list-toolbar .form-select {
            min-width: 0;
        }

        .mail-command-bar {
            background: var(--bs-tertiary-bg);
        }

        .mail-command-bar .btn {
            min-height: 2rem;
        }

        .mail-command-bar .btn-icon {
            width: 2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding-left: 0;
            padding-right: 0;
        }

        .mail-sender-avatar {
            width: 2.5rem;
            height: 2.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(var(--bs-primary-rgb), .12);
            color: var(--bs-primary);
            font-weight: 700;
            flex: 0 0 auto;
        }

        .mail-detail-strip {
            row-gap: .35rem;
        }

        .mail-detail-strip .text-truncate {
            max-width: min(100%, 32rem);
        }

        .mail-reader-subject {
            line-height: 1.25;
        }

        .mail-thread-summary-button {
            width: 100%;
            border: 0;
            background: transparent;
            text-align: left;
        }

        .mail-thread-summary-button:hover,
        .mail-thread-summary-button:focus {
            background: rgba(var(--bs-secondary-rgb), .06);
        }

        .mail-thread-item-active {
            background: rgba(var(--bs-primary-rgb), .04);
            box-shadow: inset 3px 0 0 var(--bs-primary);
        }

        .mail-thread-body {
            background: var(--bs-body-bg);
        }

        .mail-composer textarea {
            resize: vertical;
        }

        .mail-html-editor-toolbar .btn {
            min-width: 2.1rem;
        }

        .mail-ai-instruction {
            flex: 1 1 14rem;
            max-width: 22rem;
            min-width: min(100%, 12rem);
        }

        .mail-composer-inline-status {
            align-items: center;
            gap: .4rem;
            line-height: 1.3;
        }

        .mail-html-editor-surface,
        .mail-html-editor-source {
            min-height: 10rem;
            max-height: 24rem;
            overflow: auto;
        }

        .mail-html-editor-surface:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .16);
            outline: 0;
        }

        .mail-list-button {
            border: 0;
            border-bottom: 1px solid var(--bs-border-color);
            border-radius: 0;
            text-align: left;
        }

        .mail-list-button.active {
            background: rgba(var(--bs-primary-rgb), .08);
            color: var(--bs-body-color);
            box-shadow: inset 3px 0 0 var(--bs-primary);
        }

        .mail-list-button:focus-visible {
            z-index: 1;
            outline: 0;
            box-shadow: inset 3px 0 0 var(--bs-primary), 0 0 0 .2rem rgba(var(--bs-primary-rgb), .18);
        }

        .mail-list-button-expanded {
            border-bottom: 0;
        }

        .mail-conversation-list-children {
            padding: .35rem .5rem .45rem 1.25rem;
            border-bottom: 1px solid var(--bs-border-color);
            background: rgba(var(--bs-secondary-rgb), .035);
        }

        .mail-conversation-child {
            display: block;
            width: 100%;
            min-height: 2.75rem;
            padding: .5rem .65rem;
            border: 0;
            border-left: 2px solid var(--bs-border-color);
            border-radius: .25rem;
            background: transparent;
            color: var(--bs-body-color);
            text-align: left;
        }

        .mail-conversation-child:hover,
        .mail-conversation-child:focus-visible {
            background: rgba(var(--bs-secondary-rgb), .07);
        }

        .mail-conversation-child:focus-visible {
            outline: 3px solid var(--bs-primary);
            outline-offset: -3px;
        }

        .mail-conversation-child.active {
            border-left-color: var(--bs-primary);
            background: rgba(var(--bs-primary-rgb), .09);
            color: var(--bs-body-color);
            box-shadow: inset 2px 0 0 var(--bs-primary);
        }

        .mail-conversation-child-branch {
            width: 1rem;
            color: var(--bs-secondary-color);
            flex: 0 0 auto;
        }

        .mail-conversation-child-context {
            max-width: min(100%, 12rem);
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mail-conversation-parent-status {
            flex-shrink: 0;
            text-align: right;
        }

        .mail-conversation-parent-status-badges {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: .25rem;
        }

        .mail-list-button-flagged {
            background: rgba(var(--bs-warning-rgb), .07);
        }

        .mail-list-button-flagged.active {
            background: linear-gradient(90deg, rgba(var(--bs-warning-rgb), .12), rgba(var(--bs-primary-rgb), .08));
        }

        .mail-list-button-draft {
            background: rgba(var(--bs-info-rgb), .06);
        }

        .mail-list-button-draft.active {
            background: linear-gradient(90deg, rgba(var(--bs-info-rgb), .12), rgba(var(--bs-primary-rgb), .08));
        }

        .mail-flag-indicator {
            color: var(--bs-warning-text-emphasis);
        }

        .mail-classification-editor {
            background: rgba(var(--bs-secondary-rgb), .05);
        }

        .mail-min-w-0 {
            min-width: 0;
        }

        .mail-rule-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 1060;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            overflow: auto;
            padding: 2rem .75rem;
            background: rgba(0, 0, 0, .36);
        }

        .mail-rule-modal {
            width: min(52rem, 100%);
        }

        .mail-pagination nav .d-sm-flex {
            align-items: flex-start !important;
            flex-direction: column;
            gap: .5rem;
            min-width: 0;
        }

        .mail-pagination nav p {
            margin-bottom: 0;
        }

        .mail-pagination .pagination {
            flex-wrap: wrap;
            gap: .125rem;
            justify-content: flex-start;
            margin-bottom: 0;
        }

        .mail-pagination .page-link {
            min-width: 2rem;
            text-align: center;
        }

        @media (min-width: 1200px) {
            /* Stretch through the existing Mail page shell so a taller folder sidebar is useful space. */
            .tech-shell-content {
                display: flex;
                flex-direction: column;
            }

            .tech-shell-content > .content {
                flex: 1 1 auto;
                min-height: 0;
            }

            .tech-shell-content > .content > .container,
            .mail-workspace-root {
                display: flex;
                min-height: 0;
                flex: 1 1 auto;
                flex-direction: column;
            }

            .mail-workspace-grid {
                flex: 1 1 auto;
                min-height: calc(100vh - 178px);
                min-height: max(32rem, calc(100dvh - 178px));
                align-items: stretch;
            }

            .mail-pane {
                display: flex;
                min-height: 0;
                flex-direction: column;
            }

            .mail-scroll,
            .mail-reader-body {
                flex: 1 1 0;
                min-height: 0;
                max-height: none;
                overscroll-behavior: contain;
            }

            .mail-command-bar,
            .mail-pagination,
            .mail-smart-inbox-trigger {
                flex: 0 0 auto;
            }

            .mail-reader-pane-compose-only > .mail-composer {
                flex: 1 1 auto;
                min-height: 0;
                overflow-y: auto;
            }

            .mail-list-header {
                padding: .625rem .75rem !important;
            }

            .mail-list-header .mail-list-toolbar {
                gap: .375rem;
                margin-top: .5rem !important;
            }

            .mail-list-button {
                padding: .5rem .75rem !important;
            }

            .mail-conversation-parent-meta {
                margin-top: .25rem !important;
            }

            .mail-conversation-parent-status-badges {
                flex-flow: row wrap;
                justify-content: flex-end;
                gap: .125rem;
            }

            .mail-conversation-list-children {
                padding: .25rem .4rem .35rem 1rem;
            }

            .mail-conversation-child {
                min-height: 2.75rem;
                padding: .375rem .5rem;
            }
        }

        @media (max-width: 1199.98px) {
            .mail-workspace-grid {
                grid-template-columns: 1fr;
                height: auto;
                min-height: auto;
            }

            .mail-scroll,
            .mail-reader-body {
                max-height: none;
                overscroll-behavior: auto;
            }
        }

        @media (max-width: 575.98px) {
            .mail-list-toolbar {
                grid-template-columns: 1fr;
            }

            .mail-conversation-list-children {
                padding-left: .75rem;
            }

            .mail-conversation-parent-heading {
                flex-wrap: wrap;
            }

            .mail-conversation-parent-status {
                flex: 1 1 100%;
                text-align: left;
            }

            .mail-conversation-parent-status-badges {
                flex-direction: row;
                flex-wrap: wrap;
                align-items: flex-start;
            }
        }
    </style>

    @if($remoteOperationsDashboard['visible'] ?? false)
        @teleport('#mailbox-operations-rightbar-slot')
            @include('email::Livewire.Tech.partials.mailbox-operations-rightbar')
        @endteleport
    @endif

    @if($mailActionStatus)
        <div class="alert alert-{{ $mailActionStatus['type'] }} py-2 px-3 mb-3 small">
            {{ $mailActionStatus['message'] }}
        </div>
    @endif

    @if($activeBreakGlassAccesses->isNotEmpty())
        <!-- Active emergency access must never resemble an ordinary mailbox grant. -->
        <div class="alert alert-danger py-2 px-3 mb-3" role="alert" data-mail-break-glass-warning>
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                <div>
                    <div class="fw-semibold">
                        <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>
                        Emergency mailbox access is active
                    </div>
                    @foreach($activeBreakGlassAccesses as $access)
                        @php
                            $emergencyOperations = array_keys(array_filter([
                                'content view' => $access->can_view_content,
                                'search' => $access->can_search,
                                'attachment download' => $access->can_download_attachments,
                                'raw source' => $access->can_view_raw_source,
                            ]));
                        @endphp
                        <div class="small mt-1">
                            {{ $access->account?->address ?? 'Unavailable mailbox' }}:
                            {{ implode(', ', $emergencyOperations) }},
                            expires {{ $access->expires_at?->format('Y-m-d H:i') }}.
                        </div>
                    @endforeach
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('tech.mail.access.history') }}" class="btn btn-sm btn-outline-danger">Access history</a>
                    @foreach($activeBreakGlassAccesses as $access)
                        <form method="post" action="{{ route('tech.mail.access.emergency.revoke', $access->id) }}">
                            @csrf
                            <input type="hidden" name="reason" value="Activating operator revoked access from the Mail emergency banner.">
                            <button
                                type="submit"
                                class="btn btn-sm btn-danger"
                                onclick="return confirm('Revoke this emergency mailbox access now?');">
                                Revoke {{ $access->account?->address ?? 'access' }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div class="mail-workspace-grid">
        <!-- ------------------------------------------------- -->
        <!-- Message List -->
        <!-- ------------------------------------------------- -->
        <section class="mail-pane mail-list-pane" data-mail-conversation-list-pane>
            <div class="mail-list-header p-3 border-bottom">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <div>
                        <div class="fw-semibold">Messages</div>
                        <div class="text-muted small">{{ $this->conversationCountLabel($placements->total()) }}</div>
                        @if($legacyConversationListTruncated)
                            <div class="small text-warning mt-1">
                                Legacy unprojected mail is limited to the newest 100 placements in this view.
                            </div>
                        @endif
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        @if($this->canSendAndReceiveMail())
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                wire:click="sendAndReceiveMail"
                                wire:loading.attr="disabled"
                                wire:target="sendAndReceiveMail"
                                title="Send and receive">
                                <span wire:loading.remove wire:target="sendAndReceiveMail">
                                    <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
                                </span>
                                <span wire:loading wire:target="sendAndReceiveMail" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                                Send/receive
                            </button>
                        @endif
                        @if($sendableAccounts->isNotEmpty())
                            <button type="button" class="btn btn-sm btn-primary" wire:click="startCompose">
                                <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Compose
                            </button>
                        @endif
                    </div>
                </div>
                <div class="mail-list-toolbar mt-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input
                            type="search"
                            class="form-control"
                            placeholder="Search mail"
                            wire:model.live.debounce.350ms="search"
                            aria-label="Search mail">
                        @if(trim($search) !== '')
                            <button class="btn btn-outline-secondary" type="button" wire:click="clearSearch" title="Clear search">
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                                <span class="visually-hidden">Clear search</span>
                            </button>
                        @endif
                    </div>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-funnel" aria-hidden="true"></i></span>
                        <select class="form-select" wire:model.live="listFilter" aria-label="Filter mail list">
                            @foreach($this->listFilterOptions() as $filterValue => $filterLabel)
                                <option value="{{ $filterValue }}">{{ $filterLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="mail-scroll" data-mail-conversation-list>
                @forelse($placements as $placement)
                    @php($message = $placement->message)
                    @php($classification = $this->classificationForPlacement($placement))
                    @php($conversationCount = max(1, (int) ($placement->getAttribute('mail_conversation_count') ?? 1)))
                    @php($conversationIsSelected = $selectedPlacement
                        && is_string($selectedConversationGroupKey)
                        && $selectedConversationGroupKey !== ''
                        && $selectedConversationGroupKey === $placement->getAttribute('mail_conversation_group_key'))
                    @php($conversationUnreadForMe = $this->canUsePersonalUnreadForPlacement($placement)
                        ? (int) ($placement->getAttribute('mail_conversation_unread_for_me_count') ?? ($message && $this->isUnreadForMe($message) ? 1 : 0))
                        : 0)
                    <button
                        type="button"
                        id="mail-conversation-row-{{ $placement->id }}"
                        data-mail-conversation-row="{{ $placement->id }}"
                        class="mail-list-button list-group-item list-group-item-action w-100 p-3 {{ $conversationIsSelected ? 'active' : '' }} {{ $conversationIsSelected && $conversationPlacementsTotal > 1 ? 'mail-list-button-expanded' : '' }} {{ $placement->provider_flagged ? 'mail-list-button-flagged' : '' }} {{ $this->isProviderDraftPlacement($placement) ? 'mail-list-button-draft' : '' }}"
                        wire:click="selectPlacement({{ $placement->id }})"
                        @if($conversationCount > 1 || ($conversationIsSelected && $conversationPlacementsTotal > 1))
                            aria-expanded="{{ $conversationIsSelected && $conversationPlacementsTotal > 1 ? 'true' : 'false' }}"
                            @if($conversationIsSelected && $conversationPlacementsTotal > 1)
                                aria-controls="mail-conversation-children-{{ $placement->id }}"
                            @endif
                        @endif>
                        <div class="mail-conversation-parent-heading d-flex align-items-start justify-content-between gap-2">
                            <div class="mail-min-w-0">
                                <div class="fw-semibold text-truncate">{{ $message?->from_name ?: $message?->from_email ?: 'Unknown sender' }}</div>
                                <div class="d-flex align-items-center gap-1">
                                    @if($this->isProviderDraftPlacement($placement))
                                        <i class="bi bi-file-earmark-text text-info flex-shrink-0" aria-hidden="true"></i>
                                    @endif
                                    @if($placement->provider_flagged)
                                        <i class="bi bi-flag-fill mail-flag-indicator flex-shrink-0" aria-hidden="true"></i>
                                        <span class="visually-hidden">Flagged. </span>
                                    @endif
                                    <span class="text-truncate">{{ $message?->displaySubject() ?: '(no subject)' }}</span>
                                </div>
                            </div>
                            <div class="mail-conversation-parent-status">
                                <div class="small text-muted">{{ optional($message?->received_at)->format('M j H:i') ?? optional($message?->created_at)->format('M j H:i') }}</div>
                                <div class="mail-conversation-parent-status-badges">
                                    @if($conversationIsSelected && $conversationPlacementsTotal > 1)
                                        <span class="badge text-bg-light border">
                                            <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                                            {{ $this->mailCountLabel($conversationPlacementsTotal) }} in conversation
                                        </span>
                                        @if($conversationCount !== $conversationPlacementsTotal)
                                            <span class="badge text-bg-light border">{{ $this->mailCountLabel($conversationCount) }} in this view</span>
                                        @endif
                                    @elseif($conversationCount > 1)
                                        <span class="badge text-bg-light border">
                                            <i class="bi bi-chat-left-text" aria-hidden="true"></i> {{ $this->mailCountLabel($conversationCount) }} in this view
                                        </span>
                                    @endif
                                    @if($conversationUnreadForMe > 0)
                                        <span
                                            class="badge text-bg-success"
                                            data-mail-conversation-unread-for-me="{{ $conversationUnreadForMe }}"
                                            aria-label="{{ $conversationUnreadForMe > 1 ? $conversationUnreadForMe.' unread for me' : 'Unread for me' }}"
                                            title="Unread for me">
                                            {{ $conversationUnreadForMe > 1 ? $conversationUnreadForMe.' unread' : 'Unread' }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="text-muted small text-truncate mt-1">{{ $message ? $this->messagePreview($message) : 'Message content is unavailable.' }}</div>
                        <div class="mail-conversation-parent-meta d-flex flex-wrap align-items-center gap-1 mt-2">
                            <span class="badge text-bg-light border">{{ $placement->account?->address }}</span>
                            <span class="badge text-bg-light border">{{ $this->folderLabel($placement) }}</span>
                            @if($this->isProviderDraftPlacement($placement))
                                <span class="badge text-bg-info">Provider draft</span>
                            @endif
                            @if($classification?->category)
                                <span class="badge text-bg-primary">{{ $classification->category->name }}</span>
                            @endif
                            @foreach(($classification?->tags ?? collect())->take(3) as $tag)
                                <span class="badge text-bg-secondary">{{ $tag->name }}</span>
                            @endforeach
                            @if(($classification?->tags?->count() ?? 0) > 3)
                                <span class="badge text-bg-light border">+{{ $classification->tags->count() - 3 }}</span>
                            @endif
                            @if(($message?->attachments_count ?? 0) > 0)
                                <span class="badge text-bg-light border">
                                    <i class="bi bi-paperclip" aria-hidden="true"></i> {{ $message->attachments_count }}
                                    <span class="visually-hidden">attachments</span>
                                </span>
                            @endif
                            @if($message?->ticket_id && $this->canUseTicketContextForPlacement($placement))
                                <span class="badge text-bg-info">Ticket linked</span>
                            @endif
                            @if($this->sentReconciliationForPlacement($placement))
                                <span class="badge text-bg-success">Sent reconciled</span>
                            @endif
                        </div>
                    </button>

                    @if($conversationIsSelected && $conversationPlacementsTotal > 1)
                        <!-- Selected conversation message navigation -->
                        <div
                            id="mail-conversation-children-{{ $placement->id }}"
                            class="mail-conversation-list-children"
                            data-mail-conversation-children="{{ $placement->id }}"
                            role="region"
                            aria-labelledby="mail-conversation-children-heading-{{ $placement->id }}">
                            <div class="d-flex align-items-center justify-content-between gap-2 px-2 py-1 small">
                                <span id="mail-conversation-children-heading-{{ $placement->id }}" class="fw-semibold text-muted">Conversation mails</span>
                                <span class="badge text-bg-light border">{{ $this->mailCountLabel($conversationPlacementsTotal) }}</span>
                            </div>

                            <ul
                                class="list-unstyled mb-0"
                                aria-label="Emails in selected conversation">
                                @foreach($conversationPlacements as $conversationPlacement)
                                    @php($conversationMessage = $conversationPlacement->message)
                                    @php($conversationChildSelected = (int) $selectedPlacementId === (int) $conversationPlacement->id)
                                    @php($conversationChildUnread = $conversationMessage
                                        && $this->canUsePersonalUnreadForPlacement($conversationPlacement)
                                        && $this->isUnreadForMe($conversationMessage))

                                    <li wire:key="mail-conversation-child-{{ $conversationPlacement->id }}">
                                        <button
                                        type="button"
                                        id="mail-conversation-child-{{ $conversationPlacement->id }}"
                                        data-mail-conversation-child-placement-id="{{ $conversationPlacement->id }}"
                                        class="mail-conversation-child {{ $conversationChildSelected ? 'active' : '' }}"
                                        wire:click="selectPlacement({{ $conversationPlacement->id }})"
                                        @if($conversationChildSelected) aria-current="true" @endif>
                                        <span class="visually-hidden">Open email. </span>
                                        <span class="d-flex align-items-start gap-2">
                                            <span class="mail-conversation-child-branch pt-1" aria-hidden="true">
                                                <i class="bi bi-arrow-return-right"></i>
                                            </span>
                                            <span class="mail-min-w-0 flex-grow-1">
                                                @if($conversationChildSelected)
                                                    <span class="visually-hidden">Selected email. </span>
                                                @endif
                                                <span class="d-flex align-items-start justify-content-between gap-2">
                                                    <span class="mail-min-w-0 flex-grow-1 text-truncate {{ $conversationChildUnread ? 'fw-semibold' : '' }}">
                                                        {{ $conversationMessage ? $this->senderLabel($conversationMessage) : 'Unknown sender' }}
                                                    </span>
                                                    <span class="small text-muted flex-shrink-0">
                                                        {{ optional($conversationMessage?->received_at ?? $conversationMessage?->created_at)->format('M j H:i') }}
                                                    </span>
                                                </span>
                                                <span class="text-truncate d-block {{ $conversationChildUnread ? 'fw-semibold' : '' }}">
                                                    {{ $conversationMessage?->displaySubject() ?: '(no subject)' }}
                                                </span>
                                                <span class="d-flex flex-wrap align-items-center gap-1 mt-1">
                                                    <span class="mail-conversation-child-context badge text-bg-light border" title="{{ $conversationPlacement->account?->address }}">{{ $conversationPlacement->account?->address }}</span>
                                                    <span class="mail-conversation-child-context badge text-bg-light border" title="{{ $this->folderLabel($conversationPlacement) }}">{{ $this->folderLabel($conversationPlacement) }}</span>
                                                    @if($conversationChildUnread)
                                                        <span
                                                            class="badge text-bg-success"
                                                            data-mail-placement-unread-for-me="{{ $conversationPlacement->id }}"
                                                            aria-label="Unread for me"
                                                            title="Unread for me">Unread</span>
                                                    @endif
                                                    @if($conversationPlacement->provider_flagged)
                                                        <span class="badge text-bg-warning" title="Flagged">
                                                            <i class="bi bi-flag-fill" aria-hidden="true"></i>
                                                            <span class="visually-hidden">Flagged</span>
                                                        </span>
                                                    @endif
                                                    @if($this->isProviderDraftPlacement($conversationPlacement))
                                                        <span class="badge text-bg-info">Provider draft</span>
                                                    @endif
                                                    @if(($conversationMessage?->attachments_count ?? 0) > 0)
                                                        <span class="badge text-bg-light border">
                                                            <i class="bi bi-paperclip" aria-hidden="true"></i>
                                                            {{ $conversationMessage->attachments_count }}
                                                            <span class="visually-hidden">attachments</span>
                                                        </span>
                                                    @endif
                                                </span>
                                            </span>
                                        </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>

                            @if($conversationPlacementsTruncated)
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-2 pt-2 small text-muted">
                                    <span>Showing {{ $conversationPlacements->count() }} of {{ $conversationPlacementsTotal }} legacy conversation mails.</span>
                                    @if($conversationPlacementsCanLoadMore)
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-secondary"
                                            wire:click="loadMoreLegacyConversation"
                                            wire:loading.attr="disabled"
                                            wire:target="loadMoreLegacyConversation">
                                            Load more
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif
                @empty
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-inbox fs-2 d-block mb-2" aria-hidden="true"></i>
                        No mail matches this view.
                    </div>
                @endforelse
            </div>

            @if($placements->hasPages())
                <div class="mail-pagination p-3 border-top">
                    {{ $placements->onEachSide(1)->links() }}
                </div>
            @endif
        </section>

        <!-- ------------------------------------------------- -->
        <!-- Reading Pane -->
        <!-- ------------------------------------------------- -->
        <section
            class="mail-pane mail-reader-pane {{ $composerOpen && $composerMode === 'compose' && ! ($selectedPlacement && $selectedPlacement->message) ? 'mail-reader-pane-compose-only' : '' }}"
            data-mail-reader-pane>
            @if($composerOpen && $composerMode === 'compose' && ! ($selectedPlacement && $selectedPlacement->message))
                <div class="mail-command-bar border-bottom px-3 py-2">
                    <div class="d-flex align-items-center justify-content-between gap-2">
                        <div class="fw-semibold">
                            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>New message
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" wire:click="cancelComposer" title="Close composer">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                            <span class="visually-hidden">Close composer</span>
                        </button>
                    </div>
                </div>
                @include('email::Livewire.Tech.partials.mail-composer-form', ['selectedPlacement' => null])
            @elseif($selectedPlacement && $selectedPlacement->message)
                @php($selectedMessage = $selectedPlacement->message)
                @php($canOrganizeSelected = $this->canOrganizePlacement($selectedPlacement))
                @php($canSendSelected = $this->canSendFromPlacement($selectedPlacement))
                @php($canMarkSpamSelected = $this->canMarkSpamPlacement($selectedPlacement))
                @php($canCreateTicketSelected = $this->canCreateTicketFromPlacement($selectedPlacement))
                @php($canOpenTicketSelected = $this->canOpenTicketFromPlacement($selectedPlacement))
                @php($canLinkTicketSelected = $this->canLinkTicketFromPlacement($selectedPlacement))
                @php($canUseAiSelected = $this->canUseAiAction($selectedPlacement))
                @php($canUsePersonalUnreadSelected = $this->canUsePersonalUnreadForPlacement($selectedPlacement))
                @php($selectedUsesBreakGlass = $this->usesBreakGlassForPlacement($selectedPlacement))
                @php($selectedClassification = $this->classificationForPlacement($selectedPlacement))
                @php($ticketConversationLinks = $this->ticketConversationLinksForPlacement($selectedPlacement))
                @php($senderName = trim((string) $selectedMessage->from_name))
                @php($senderEmail = trim((string) $selectedMessage->from_email))
                @php($senderPrimary = $senderName !== '' ? $senderName : ($senderEmail !== '' ? $senderEmail : 'Unknown sender'))
                <div class="mail-command-bar border-bottom px-3 py-2">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            @if($this->isProviderDraftPlacement($selectedPlacement) && $this->canEditProviderDraftPlacement($selectedPlacement))
                                <button type="button" class="btn btn-sm btn-primary" wire:click="editProviderDraft">
                                    <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edit draft
                                </button>
                            @elseif($canSendSelected)
                                <button type="button" class="btn btn-sm btn-primary" wire:click="startReply">
                                    <i class="bi bi-reply me-1" aria-hidden="true"></i>Reply
                                </button>
                                @if($this->canReplyAllFromPlacement($selectedPlacement))
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="startReplyAll">
                                        <i class="bi bi-reply-all me-1" aria-hidden="true"></i>Reply all
                                    </button>
                                @endif
                                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="startForward">
                                    <i class="bi bi-forward me-1" aria-hidden="true"></i>Forward
                                </button>
                            @endif

                            @if($canUsePersonalUnreadSelected && $this->isUnreadForMe($selectedMessage))
                                <button type="button" class="btn btn-sm btn-outline-success" wire:click="markSelectedReadForMe">
                                    <i class="bi bi-envelope-open me-1" aria-hidden="true"></i>Mark read for me
                                </button>
                            @endif

                            @if($canMarkSpamSelected)
                                <button type="button" class="btn btn-sm btn-outline-warning btn-icon" wire:click="markSelectedSpam" onclick="return confirm('Mark this sender as spam, update the spam rule, and archive the provider placement when possible?');" title="Mark as spam">
                                    <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
                                    <span class="visually-hidden">Mark as spam</span>
                                </button>
                            @endif

                            @if($canCreateTicketSelected)
                                <button type="button" class="btn btn-sm btn-outline-info btn-icon" wire:click="createTicketForSelected" title="Create or link Ticket">
                                    <i class="bi bi-ticket-perforated" aria-hidden="true"></i>
                                    <span class="visually-hidden">Create or link Ticket</span>
                                </button>
                            @elseif($canOpenTicketSelected && Route::has('tech.tickets.show') && $selectedMessage->ticket)
                                <a href="{{ route('tech.tickets.show', $selectedMessage->ticket) }}" class="btn btn-sm btn-outline-info btn-icon" title="Open linked Ticket">
                                    <i class="bi bi-ticket-perforated-fill" aria-hidden="true"></i>
                                    <span class="visually-hidden">Open linked Ticket</span>
                                </a>
                            @endif
                        </div>

                        <div class="d-flex align-items-center gap-2 ms-auto">
                            @if($this->canViewRawSourceForPlacement($selectedPlacement))
                                <a
                                    href="{{ route('tech.mail.raw-source.show', $selectedPlacement) }}"
                                    class="btn btn-sm btn-outline-secondary btn-icon"
                                    target="_blank"
                                    rel="noopener"
                                    title="View raw source">
                                    <i class="bi bi-filetype-eml" aria-hidden="true"></i>
                                    <span class="visually-hidden">View raw source</span>
                                </a>
                            @endif

                            @if($canUseAiSelected)
                                <button type="button" class="btn btn-sm btn-outline-primary btn-icon" wire:click="generateMailAiSummary" wire:loading.attr="disabled" wire:target="generateMailAiSummary" title="AI summary">
                                    <span wire:loading.remove wire:target="generateMailAiSummary">
                                        <i class="bi bi-stars" aria-hidden="true"></i>
                                    </span>
                                    <span wire:loading wire:target="generateMailAiSummary" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                                    <span class="visually-hidden">AI summary</span>
                                </button>
                            @endif

                            @if($canOrganizeSelected && $this->hasTrashTarget($selectedPlacement))
                                <button type="button" class="btn btn-sm btn-outline-danger btn-icon" wire:click="trashSelected" onclick="return confirm('Move this message to mailbox Trash?');" title="Move to Trash">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                    <span class="visually-hidden">Move to Trash</span>
                                </button>
                            @endif

                            @if($canOrganizeSelected || ($canUsePersonalUnreadSelected && ! $this->isUnreadForMe($selectedMessage)) || $this->canUseRuleAction($selectedPlacement) || $canLinkTicketSelected || ($this->conversationAcknowledgementAvailable() && $selectedPlacement->email_conversation_id))
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" data-bs-toggle="dropdown" aria-expanded="false" title="More actions">
                                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                        <span class="visually-hidden">More actions</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        @if($this->conversationAcknowledgementAvailable() && $selectedPlacement->email_conversation_id)
                                            <li>
                                                <button type="button" class="dropdown-item" wire:click="openConversationAcknowledgement(false)">
                                                    <i class="bi bi-envelope-open me-2" aria-hidden="true"></i>Mark conversation read for me
                                                </button>
                                            </li>
                                            <li>
                                                <button type="button" class="dropdown-item" wire:click="openConversationAcknowledgement(true)">
                                                    <i class="bi bi-envelope-exclamation me-2" aria-hidden="true"></i>Mark conversation unread for me
                                                </button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                        @endif
                                        @if($canUsePersonalUnreadSelected && ! $this->isUnreadForMe($selectedMessage))
                                            <li>
                                                <button type="button" class="dropdown-item" wire:click="setSelectedUnreadForMe(true)">
                                                    <i class="bi bi-envelope-exclamation me-2" aria-hidden="true"></i>Mark unread for me
                                                </button>
                                            </li>
                                        @endif
                                        @if($canOrganizeSelected)
                                            @if($canUsePersonalUnreadSelected && ! $this->isUnreadForMe($selectedMessage))
                                                <li><hr class="dropdown-divider"></li>
                                            @endif
                                            <li>
                                                @if($selectedPlacement->provider_seen)
                                                    <button type="button" class="dropdown-item" wire:click="setProviderSeenForSelected(false)">
                                                        <i class="bi bi-envelope me-2" aria-hidden="true"></i>Mark unread on mail server
                                                    </button>
                                                @else
                                                    <button type="button" class="dropdown-item" wire:click="setProviderSeenForSelected(true)">
                                                        <i class="bi bi-envelope-open me-2" aria-hidden="true"></i>Mark read on mail server
                                                    </button>
                                                @endif
                                            </li>
                                            <li>
                                                @if($selectedPlacement->provider_flagged)
                                                    <button type="button" class="dropdown-item" wire:click="setProviderFlaggedForSelected(false)">
                                                        <i class="bi bi-flag-fill me-2" aria-hidden="true"></i>Unflag in mailbox
                                                    </button>
                                                @else
                                                    <button type="button" class="dropdown-item" wire:click="setProviderFlaggedForSelected(true)">
                                                        <i class="bi bi-flag me-2" aria-hidden="true"></i>Flag in mailbox
                                                    </button>
                                                @endif
                                            </li>
                                            @if($this->hasMoveTargets($selectedPlacement))
                                                <li>
                                                    <button type="button" class="dropdown-item" wire:click="toggleMovePanel">
                                                        <i class="bi bi-folder-symlink me-2" aria-hidden="true"></i>{{ $movePanelOpen ? 'Hide move to folder' : 'Move to folder' }}
                                                    </button>
                                                </li>
                                            @endif
                                            @if($this->hasArchiveTarget($selectedPlacement))
                                                <li>
                                                    <button type="button" class="dropdown-item" wire:click="archiveSelected">
                                                        <i class="bi bi-archive me-2" aria-hidden="true"></i>Archive
                                                    </button>
                                                </li>
                                            @endif
                                            @if($this->canUseRuleAction($selectedPlacement))
                                                <li>
                                                    <button type="button" class="dropdown-item" wire:click="openRuleAction">
                                                        <i class="bi bi-signpost-split me-2" aria-hidden="true"></i>Add rule
                                                    </button>
                                                </li>
                                            @endif
                                            @if($canLinkTicketSelected)
                                                <li>
                                                    <button type="button" class="dropdown-item" wire:click="toggleTicketLinkPanel">
                                                        <i class="bi bi-ticket-detailed me-2" aria-hidden="true"></i>{{ $ticketLinkPanelOpen ? 'Hide Ticket link' : 'Link existing Ticket' }}
                                                    </button>
                                                </li>
                                            @endif
                                            <li>
                                                <button type="button" class="dropdown-item" wire:click="suppressSelectedTicketCorrelation" onclick="return confirm('Mark this whole Mail conversation as not a Ticket? Future messages in it will not create or join Tickets automatically.');">
                                                    <i class="bi bi-ticket-detailed-fill me-2" aria-hidden="true"></i>Not a Ticket
                                                </button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <button type="button" class="dropdown-item" wire:click="toggleClassificationEditor">
                                                    <i class="bi bi-tags me-2" aria-hidden="true"></i>{{ $classificationEditorOpen ? 'Hide category and tags' : 'Category and tags' }}
                                                </button>
                                            </li>
                                        @elseif($this->canUseRuleAction($selectedPlacement))
                                            @if($canUsePersonalUnreadSelected && ! $this->isUnreadForMe($selectedMessage))
                                                <li><hr class="dropdown-divider"></li>
                                            @endif
                                            <li>
                                                <button type="button" class="dropdown-item" wire:click="openRuleAction">
                                                    <i class="bi bi-signpost-split me-2" aria-hidden="true"></i>Add rule
                                                </button>
                                            </li>
                                        @endif
                                        @if(! $canOrganizeSelected && $canLinkTicketSelected)
                                            <li>
                                                <button type="button" class="dropdown-item" wire:click="toggleTicketLinkPanel">
                                                    <i class="bi bi-ticket-detailed me-2" aria-hidden="true"></i>{{ $ticketLinkPanelOpen ? 'Hide Ticket link' : 'Link existing Ticket' }}
                                                </button>
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            @endif
                        </div>
	                    </div>

                        @if($conversationAcknowledgementOpen)
                            <div class="mail-classification-editor border rounded p-3 mt-2" role="region" aria-labelledby="mail-conversation-acknowledgement-title">
                                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                                    <div>
                                        <h2 id="mail-conversation-acknowledgement-title" class="h6 mb-1">
                                            {{ $conversationAcknowledgementTargetUnread ? 'Mark conversation unread for me' : 'Mark conversation read for me' }}
                                        </h2>
                                        <p class="small text-muted mb-0">
                                            Preview freezes only the currently visible messages in this mailbox conversation. New mail and other users are not changed.
                                        </p>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" wire:click="closeConversationAcknowledgement" title="Close conversation action">
                                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                                        <span class="visually-hidden">Close conversation action</span>
                                    </button>
                                </div>

                                @if(! $conversationAcknowledgementSummary)
                                    @if($canOrganizeSelected && ! $conversationAcknowledgementTargetUnread)
                                        <div class="form-check mt-3">
                                            <input id="mail-conversation-provider-seen" class="form-check-input" type="checkbox" wire:model="conversationAcknowledgementProviderSeen">
                                            <label for="mail-conversation-provider-seen" class="form-check-label">
                                                Also request Mailbox read for each exact provider placement
                                            </label>
                                            <div class="form-text">This is a separate provider action and may remain pending or fail after the personal result succeeds.</div>
                                        </div>
                                    @endif
                                    <div class="d-flex justify-content-end mt-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="previewConversationAcknowledgement">
                                            <i class="bi bi-eye me-1" aria-hidden="true"></i>Create preview
                                        </button>
                                    </div>
                                @else
                                    <div class="row g-2 mt-2" aria-live="polite">
                                        <div class="col-6 col-md"><div class="border rounded p-2"><div class="small text-muted">Status</div><div class="fw-semibold">{{ ucfirst(str_replace('_', ' ', $conversationAcknowledgementSummary['status'])) }}</div></div></div>
                                        <div class="col-6 col-md"><div class="border rounded p-2"><div class="small text-muted">Frozen messages</div><div class="fw-semibold">{{ $conversationAcknowledgementSummary['items'] }}</div></div></div>
                                        <div class="col-6 col-md"><div class="border rounded p-2"><div class="small text-muted">Personal applied</div><div class="fw-semibold">{{ $conversationAcknowledgementSummary['personal_applied'] }}</div></div></div>
                                        <div class="col-6 col-md"><div class="border rounded p-2"><div class="small text-muted">Mailbox pending</div><div class="fw-semibold">{{ $conversationAcknowledgementSummary['provider_pending'] }}</div></div></div>
                                        <div class="col-6 col-md"><div class="border rounded p-2"><div class="small text-muted">Problems</div><div class="fw-semibold">{{ $conversationAcknowledgementSummary['denied'] + $conversationAcknowledgementSummary['stale'] + $conversationAcknowledgementSummary['failed'] }}</div></div></div>
                                    </div>
                                    @if($conversationAcknowledgementSummary['expires_at'] && $conversationAcknowledgementSummary['status'] === 'previewed')
                                        <div class="small text-muted mt-2">Preview expires {{ $conversationAcknowledgementSummary['expires_at'] }}.</div>
                                    @endif
                                    <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="refreshConversationAcknowledgement">Refresh status</button>
                                        @if(! $conversationAcknowledgementSummary['terminal'])
                                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="cancelConversationAcknowledgement">Cancel</button>
                                        @endif
                                        @if($conversationAcknowledgementSummary['status'] === 'previewed')
                                            <button type="button" class="btn btn-sm btn-primary" wire:click="applyConversationAcknowledgement" onclick="return confirm('Apply this exact frozen conversation action?');">
                                                Confirm and apply
                                            </button>
                                        @elseif($conversationAcknowledgementSummary['status'] === 'partial')
                                            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="applyConversationAcknowledgement">Retry unresolved</button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif

	                    @if($personalRuleModalOpen)
	                        @php($personalRuleAttempts = $this->ruleExecutionAttemptsForPlacement($selectedPlacement))
	                        @php($personalRuleActionOptions = $this->personalRuleActionOptions($selectedPlacement))
	                        @php($personalRuleMoveTargets = $this->moveTargetFolders($selectedPlacement))
	                        <div class="mail-rule-modal-backdrop">
	                            <div class="mail-rule-modal modal-dialog modal-dialog-scrollable" role="dialog" aria-modal="true" aria-labelledby="mail-personal-rule-title">
	                                <div class="modal-content shadow">
	                                    <div class="modal-header py-2">
	                                        <h2 id="mail-personal-rule-title" class="modal-title h5">Personal rule</h2>
	                                        <button type="button" class="btn-close" wire:click="closePersonalRuleModal" aria-label="Close"></button>
	                                    </div>
	                                    <div class="modal-body">
	                                        <div class="row g-3">
	                                            <div class="col-12 col-lg-5">
	                                                <div class="fw-semibold small mb-2">Rule history</div>
	                                                @forelse($personalRuleAttempts as $attempt)
	                                                    <div class="border rounded p-2 mb-2">
	                                                        <div class="d-flex align-items-start justify-content-between gap-2">
	                                                            <div class="small fw-semibold">{{ $attempt->rule?->name ?: 'Rule snapshot' }}</div>
	                                                            <span class="badge {{ $attempt->status === 'succeeded' ? 'text-bg-success' : ($attempt->status === 'failed' ? 'text-bg-danger' : 'text-bg-secondary') }}">
	                                                                {{ ucfirst($attempt->status) }}
	                                                            </span>
	                                                        </div>
	                                                        <div class="small text-muted mt-1">
	                                                            {{ collect($attempt->actions_json ?? [])->pluck('type')->map(fn ($type) => str_replace('_', ' ', (string) $type))->filter()->implode(', ') ?: 'No actions' }}
	                                                        </div>
	                                                        @if($attempt->finished_at || $attempt->started_at)
	                                                            <div class="small text-muted">{{ optional($attempt->finished_at ?: $attempt->started_at)->format('Y-m-d H:i') }}</div>
	                                                        @endif
	                                                    </div>
	                                                @empty
	                                                    <div class="text-muted small border rounded p-2">No matched rule executions are recorded for this message.</div>
	                                                @endforelse
	                                            </div>
	                                            <div class="col-12 col-lg-7">
	                                                <div class="row g-2">
	                                                    <div class="col-12">
	                                                        <label for="mail-personal-rule-name" class="form-label small fw-semibold mb-1">Name</label>
	                                                        <input id="mail-personal-rule-name" class="form-control form-control-sm @error('personalRuleName') is-invalid @enderror" wire:model.defer="personalRuleName">
	                                                        @error('personalRuleName')<div class="invalid-feedback">{{ $message }}</div>@enderror
	                                                    </div>
	                                                    <div class="col-12 col-md-5">
	                                                        <label for="mail-personal-rule-condition" class="form-label small fw-semibold mb-1">When</label>
	                                                        <select id="mail-personal-rule-condition" class="form-select form-select-sm @error('personalRuleConditionField') is-invalid @enderror" wire:model.live="personalRuleConditionField">
	                                                            @foreach($this->personalRuleConditionOptions() as $value => $label)
	                                                                <option value="{{ $value }}">{{ $label }}</option>
	                                                            @endforeach
	                                                        </select>
	                                                        @error('personalRuleConditionField')<div class="invalid-feedback">{{ $message }}</div>@enderror
	                                                    </div>
	                                                    <div class="col-12 col-md-7">
	                                                        <label for="mail-personal-rule-value" class="form-label small fw-semibold mb-1">Value</label>
	                                                        <input id="mail-personal-rule-value" class="form-control form-control-sm @error('personalRuleConditionValue') is-invalid @enderror" wire:model.defer="personalRuleConditionValue">
	                                                        @error('personalRuleConditionValue')<div class="invalid-feedback">{{ $message }}</div>@enderror
	                                                    </div>
	                                                    <div class="col-12 col-md-5">
	                                                        <label for="mail-personal-rule-action" class="form-label small fw-semibold mb-1">Then</label>
	                                                        <select id="mail-personal-rule-action" class="form-select form-select-sm @error('personalRuleActionType') is-invalid @enderror" wire:model.live="personalRuleActionType">
	                                                            @foreach($personalRuleActionOptions as $value => $label)
	                                                                <option value="{{ $value }}">{{ $label }}</option>
	                                                            @endforeach
	                                                        </select>
	                                                        @error('personalRuleActionType')<div class="invalid-feedback">{{ $message }}</div>@enderror
	                                                    </div>
	                                                    @if($personalRuleActionType === 'move_to_folder')
	                                                        <div class="col-12 col-md-7">
	                                                            <label for="mail-personal-rule-folder" class="form-label small fw-semibold mb-1">Folder</label>
	                                                            <select id="mail-personal-rule-folder" class="form-select form-select-sm @error('personalRuleTargetFolderId') is-invalid @enderror" wire:model.defer="personalRuleTargetFolderId">
	                                                                @foreach($personalRuleMoveTargets as $folder)
	                                                                    <option value="{{ $folder->id }}">{{ $folder->name ?: $folder->path }}{{ $folder->role !== 'custom' ? ' / '.$folder->role : '' }}</option>
	                                                                @endforeach
	                                                            </select>
	                                                            @error('personalRuleTargetFolderId')<div class="invalid-feedback">{{ $message }}</div>@enderror
	                                                        </div>
	                                                    @endif
	                                                </div>
	                                            </div>
	                                        </div>
	                                    </div>
	                                    <div class="modal-footer py-2">
	                                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="closePersonalRuleModal">Cancel</button>
	                                        <button type="button" class="btn btn-sm btn-primary" wire:click="createPersonalRule" @disabled(empty($personalRuleActionOptions))>
	                                            <i class="bi bi-signpost-split me-1" aria-hidden="true"></i>Create rule
	                                        </button>
	                                    </div>
	                                </div>
	                            </div>
	                        </div>
	                    @endif

                    @if($canLinkTicketSelected && $ticketLinkPanelOpen)
                        <div class="mail-classification-editor border rounded p-2 mt-2">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-xl">
                                    <label for="mail-ticket-link-target" class="form-label small fw-semibold mb-1">Existing Ticket</label>
                                    <input
                                        id="mail-ticket-link-target"
                                        type="text"
                                        class="form-control form-control-sm @error('ticketLinkTarget') is-invalid @enderror"
                                        wire:model.defer="ticketLinkTarget"
                                        placeholder="TD-2026-000001 or ID">
                                    @error('ticketLinkTarget')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12 col-xl-auto d-flex flex-wrap justify-content-end gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="linkSelectedToTicket">
                                        <i class="bi bi-ticket-detailed me-1" aria-hidden="true"></i>Link
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" wire:click="toggleTicketLinkPanel" title="Close Ticket link">
                                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                                        <span class="visually-hidden">Close Ticket link</span>
                                    </button>
                                </div>
                            </div>
                            @if($ticketConversationLinks->isNotEmpty())
                                <div class="d-flex flex-wrap gap-1 mt-2">
                                    @foreach($ticketConversationLinks as $link)
                                        @if($link->ticket)
                                            <a
                                                href="{{ Route::has('tech.tickets.show') ? route('tech.tickets.show', $link->ticket) : '#' }}"
                                                class="badge text-bg-light border text-decoration-none">
                                                {{ $link->ticket->ticket_key }} / {{ ucfirst($link->relationship_role) }}
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

	                    @if($canOrganizeSelected && $movePanelOpen)
	                        @php($moveTargets = $this->moveTargetFolders($selectedPlacement))
                        <div class="mail-classification-editor border rounded p-2 mt-2">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-xl">
                                    <label for="mail-move-target-folder" class="form-label small fw-semibold mb-1">Move to folder</label>
                                    <select id="mail-move-target-folder" class="form-select form-select-sm @error('moveTargetFolderId') is-invalid @enderror" wire:model.defer="moveTargetFolderId">
                                        @foreach($moveTargets as $folder)
                                            <option value="{{ $folder->id }}">{{ $folder->name ?: $folder->path }}{{ $folder->role !== 'custom' ? ' / '.$folder->role : '' }}</option>
                                        @endforeach
                                    </select>
                                    @error('moveTargetFolderId')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12 col-xl-auto d-flex flex-wrap justify-content-end gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="moveSelectedToFolder" @disabled($moveTargets->isEmpty())>
                                        <i class="bi bi-folder-symlink me-1" aria-hidden="true"></i>Move
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" wire:click="toggleMovePanel" title="Close move to folder">
                                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                                        <span class="visually-hidden">Close move to folder</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if($canOrganizeSelected && $classificationEditorOpen)
                        <div class="mail-classification-editor border rounded p-2 mt-2">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                <div class="d-flex flex-wrap align-items-center gap-1">
                                    @if($selectedClassification?->category || ($selectedClassification?->tags?->isNotEmpty() ?? false))
                                        @if($selectedClassification?->category)
                                            <span class="badge text-bg-primary">{{ $selectedClassification->category->name }}</span>
                                        @endif
                                        @foreach(($selectedClassification?->tags ?? collect()) as $tag)
                                            <span class="badge text-bg-secondary">{{ $tag->name }}</span>
                                        @endforeach
                                    @else
                                        <span class="small text-muted">No category or tags</span>
                                    @endif
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" wire:click="toggleClassificationEditor" title="Close category and tags">
                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                    <span class="visually-hidden">Close category and tags</span>
                                </button>
                            </div>
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-xl-4">
                                    <label for="mail-classification-category" class="form-label small fw-semibold mb-1">Conversation category</label>
                                    <select id="mail-classification-category" class="form-select form-select-sm @error('classificationCategoryId') is-invalid @enderror" wire:model.defer="classificationCategoryId">
                                        <option value="">No category</option>
                                        @foreach($mailCategories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('classificationCategoryId')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12 col-xl">
                                    <label for="mail-classification-tags" class="form-label small fw-semibold mb-1">Tags</label>
                                    <input
                                        id="mail-classification-tags"
                                        type="text"
                                        class="form-control form-control-sm @error('classificationTagsInput') is-invalid @enderror"
                                        wire:model.defer="classificationTagsInput"
                                        list="mail-tag-suggestions"
                                        placeholder="tag, tag">
                                    @error('classificationTagsInput')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12 col-xl-auto d-flex flex-wrap justify-content-end gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="saveClassification">
                                        <i class="bi bi-tags me-1" aria-hidden="true"></i>Apply
                                    </button>
                                    @if($selectedClassification?->category || ($selectedClassification?->tags?->isNotEmpty() ?? false))
                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" wire:click="clearClassification" title="Clear classification">
                                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                                            <span class="visually-hidden">Clear classification</span>
                                        </button>
                                    @endif
                                </div>
                            </div>
                            <datalist id="mail-tag-suggestions">
                                @foreach($mailTags as $tag)
                                    <option value="{{ $tag->name }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                    @endif
                </div>

                @if($composerOpen)
                    @include('email::Livewire.Tech.partials.mail-composer-form', ['selectedPlacement' => $selectedPlacement])
                @endif

                @if($selectedPlacement->email_conversation_id && ! $selectedUsesBreakGlass)
                    <!-- Smart Inbox keeps one scoped owner: its trigger is here and its results teleport below the thread. -->
                    <livewire:tech.mail.smart-inbox-review-queue :conversation-id="$selectedPlacement->email_conversation_id" :selected-placement-id="$selectedPlacement->id" :key="'smart-inbox-review-'.$selectedPlacement->email_conversation_id.'-'.$selectedPlacement->id" />
                @endif

                @php($threadPlacements = $conversationPlacements->isNotEmpty() ? $conversationPlacements : collect([$selectedPlacement]))

                <div class="mail-reader-body" data-mail-reader-body>
                    @if($conversationPlacementsTotal > 1)
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-2 border-bottom bg-light">
                            <div class="fw-semibold">Conversation</div>
                            <span class="badge text-bg-light border">{{ $this->mailCountLabel($conversationPlacementsTotal) }}</span>
                        </div>
                    @endif

                    @if($conversationPlacementsTruncated)
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-2 border-bottom bg-warning-subtle">
                            <div class="small">
                                Showing {{ $threadPlacements->count() }} of {{ $conversationPlacementsTotal }} legacy conversation mails.
                                @if(! $conversationPlacementsCanLoadMore)
                                    The compatibility reader is capped at 200 mails.
                                @endif
                            </div>
                            @if($conversationPlacementsCanLoadMore)
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="loadMoreLegacyConversation">
                                    Load more
                                </button>
                            @endif
                        </div>
                    @endif

                    <div class="mail-conversation-thread">
                        @foreach($threadPlacements as $threadPlacement)
                            @php($threadMessage = $threadPlacement->message)
                            @php($isThreadSelected = (int) $selectedPlacementId === (int) $threadPlacement->id)
                            @php($threadSenderName = trim((string) $threadMessage?->from_name))
                            @php($threadSenderEmail = trim((string) $threadMessage?->from_email))
                            @php($threadSenderPrimary = $threadSenderName !== '' ? $threadSenderName : ($threadSenderEmail !== '' ? $threadSenderEmail : 'Unknown sender'))

                            <div class="mail-thread-item border-bottom {{ $isThreadSelected ? 'mail-thread-item-active' : '' }}">
                                <button
                                    type="button"
                                    class="mail-thread-summary-button p-3"
                                    wire:click="selectPlacement({{ $threadPlacement->id }})"
                                    aria-expanded="{{ $isThreadSelected ? 'true' : 'false' }}">
                                    <span class="d-flex flex-wrap align-items-start gap-3">
                                        @if($threadMessage)
                                            <span class="mail-sender-avatar" aria-hidden="true">{{ $this->senderInitial($threadMessage) }}</span>
                                        @endif
                                        <span class="mail-min-w-0 flex-grow-1 d-block">
                                            <span class="mail-reader-subject h5 mb-2 text-truncate d-block" role="heading" aria-level="2">{{ $threadMessage?->displaySubject() ?: '(no subject)' }}</span>
                                            <span class="d-flex flex-wrap align-items-baseline gap-2">
                                                <span class="fw-semibold text-truncate">{{ $threadSenderPrimary }}</span>
                                                @if($threadSenderName !== '' && $threadSenderEmail !== '')
                                                    <span class="text-muted small text-truncate">&lt;{{ $threadSenderEmail }}&gt;</span>
                                                @endif
                                            </span>
                                            @if($threadMessage)
                                                <span class="small text-muted text-truncate d-block">To {{ $this->recipientSummary($threadMessage) ?: 'not stored' }}</span>
                                            @endif
                                            <span class="mail-detail-strip d-flex flex-wrap align-items-center gap-2 small text-muted mt-2">
                                                <span>{{ $threadPlacement->account?->address }}</span>
                                                <span aria-hidden="true">/</span>
                                                <span>{{ $this->folderLabel($threadPlacement) }}</span>
                                                @if($threadMessage?->message_id)
                                                    <span aria-hidden="true">/</span>
                                                    <span class="text-truncate">Message ID {{ $threadMessage->message_id }}</span>
                                                @endif
                                                @if($isThreadSelected && $threadMessage?->ticket_id && $this->canUseTicketContextForPlacement($threadPlacement))
                                                    <span aria-hidden="true">/</span>
                                                    <span>{{ $threadMessage->ticket?->ticket_key ?: 'Ticket #'.$threadMessage->ticket_id }}</span>
                                                @endif
                                                @if($isThreadSelected && $ticketConversationLinks->count() > 1)
                                                    <span aria-hidden="true">/</span>
                                                    <span>{{ $ticketConversationLinks->count() }} Ticket conversation links</span>
                                                @endif
                                            </span>
                                        </span>
                                        <span class="text-end flex-shrink-0 ms-auto d-block">
                                            <span class="small text-muted d-block">{{ optional($threadMessage?->received_at ?? $threadMessage?->created_at)->format('Y-m-d H:i') }}</span>
                                            <span class="d-flex flex-wrap justify-content-end gap-1 mt-2">
                                                @if($threadMessage && $this->canUsePersonalUnreadForPlacement($threadPlacement))
                                                    <span class="badge {{ $this->isUnreadForMe($threadMessage) ? 'text-bg-success' : 'text-bg-light border' }}">
                                                        {{ $this->isUnreadForMe($threadMessage) ? 'Unread for me' : 'Read for me' }}
                                                    </span>
                                                    <span class="badge {{ $threadPlacement->provider_seen ? 'text-bg-light border' : 'text-bg-warning' }}">
                                                        {{ $threadPlacement->provider_seen ? 'Mailbox read' : 'Mailbox unread' }}
                                                    </span>
                                                @endif
                                                @if($threadPlacement->provider_answered)
                                                    <span class="badge text-bg-light border">Answered</span>
                                                @endif
                                                @if($this->isProviderDraftPlacement($threadPlacement))
                                                    <span class="badge text-bg-info">Provider draft</span>
                                                @endif
                                                @if($threadPlacement->provider_flagged)
                                                    <span class="badge text-bg-warning">
                                                        <i class="bi bi-flag-fill me-1" aria-hidden="true"></i>Flagged
                                                    </span>
                                                @endif
                                                @if($this->sentReconciliationForPlacement($threadPlacement))
                                                    <span class="badge text-bg-success">Sent reconciled</span>
                                                @endif
                                            </span>
                                        </span>
                                    </span>
                                </button>

                                @if($isThreadSelected)
                                    <div class="mail-thread-body px-3 pb-3">
                                        @if($mailAiSummary)
                                            <div class="border rounded bg-light p-3 mb-3">
                                                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
                                                    <div>
                                                        <div class="fw-semibold">
                                                            <i class="bi bi-stars me-1" aria-hidden="true"></i>AI summary
                                                        </div>
                                                        <div class="small text-muted">
                                                            {{ ucfirst($mailAiSummary['urgency'] ?? 'unknown') }} urgency
                                                            <span aria-hidden="true">/</span>
                                                            {{ ($mailAiSummary['reply_needed'] ?? false) ? 'Reply likely needed' : 'No direct reply need detected' }}
                                                            @if(data_get($mailAiSummary, 'metadata.execution_id'))
                                                                <span aria-hidden="true">/</span>
                                                                <span class="font-monospace">{{ str(data_get($mailAiSummary, 'metadata.execution_id'))->limit(18) }}</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                    <div class="d-flex align-items-center gap-2">
                                                        @if($this->canUseAiTicketCreateAction($selectedPlacement))
                                                            <button type="button" class="btn btn-sm btn-outline-info" wire:click="createTicketFromAiReview">
                                                                <i class="bi bi-ticket-perforated me-1" aria-hidden="true"></i>Create Ticket
                                                            </button>
                                                        @endif
                                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" wire:click="clearMailAiSummary" title="Close AI summary">
                                                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                                                            <span class="visually-hidden">Close AI summary</span>
                                                        </button>
                                                    </div>
                                                </div>

                                                <p class="mb-2">{{ $mailAiSummary['summary'] ?? '' }}</p>

                                                <div class="row g-3">
                                                    @if(! empty($mailAiSummary['key_points']))
                                                        <div class="col-12 col-xl-6">
                                                            <div class="small fw-semibold mb-1">Key points</div>
                                                            <ul class="small mb-0 ps-3">
                                                                @foreach($mailAiSummary['key_points'] as $point)
                                                                    <li>{{ $point }}</li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif

                                                    @if(! empty($mailAiSummary['questions']))
                                                        <div class="col-12 col-xl-6">
                                                            <div class="small fw-semibold mb-1">Questions</div>
                                                            <ul class="small mb-0 ps-3">
                                                                @foreach($mailAiSummary['questions'] as $question)
                                                                    <li>{{ $question }}</li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif

                                                    @if(! empty($mailAiSummary['action_items']))
                                                        <div class="col-12">
                                                            <div class="small fw-semibold mb-1">Action items</div>
                                                            <div class="d-grid gap-1">
                                                                @foreach($mailAiSummary['action_items'] as $item)
                                                                    <div class="small border rounded bg-white p-2">
                                                                        <span>{{ $item['text'] ?? '' }}</span>
                                                                        @if(! empty($item['owner']) || ! empty($item['due_at']))
                                                                            <span class="text-muted">
                                                                                {{ ! empty($item['owner']) ? ' / '.$item['owner'] : '' }}
                                                                                {{ ! empty($item['due_at']) ? ' / '.$item['due_at'] : '' }}
                                                                            </span>
                                                                        @endif
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    @endif

                                                    @if(! empty($mailAiSummary['suggested_labels']))
                                                        <div class="col-12">
                                                            <div class="small fw-semibold mb-1">Suggestions</div>
                                                            <div class="d-flex flex-wrap gap-1">
                                                                @foreach($mailAiSummary['suggested_labels'] as $label)
                                                                    <span class="badge text-bg-light border" title="{{ $label['reason'] ?? '' }}">
                                                                        {{ ucfirst($label['type'] ?? 'other') }}: {{ $label['label'] ?? '' }}
                                                                    </span>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    @endif

                                                    @if(! empty(data_get($mailAiSummary, 'provenance.limitations')))
                                                        <div class="col-12">
                                                            <div class="small text-muted">
                                                                {{ collect(data_get($mailAiSummary, 'provenance.limitations', []))->implode(' ') }}
                                                            </div>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif

                                        @if($threadMessage?->body_html_sanitized)
                                            <div class="email-body">
                                                {!! $threadMessage->body_html_sanitized !!}
                                            </div>
                                        @elseif($threadMessage?->body_text)
                                            <pre class="bg-light border rounded p-3 mb-0" style="white-space: pre-wrap;">{{ $threadMessage->body_text }}</pre>
                                        @else
                                            <div class="text-muted">No body content.</div>
                                        @endif

                                        @if($threadMessage?->attachments?->isNotEmpty())
                                            <div class="mt-3 pt-3 border-top">
                                                <div class="fw-semibold mb-2">
                                                    <i class="bi bi-paperclip me-1" aria-hidden="true"></i>Attachments
                                                </div>
                                                <ul class="list-unstyled mb-0">
                                                    @foreach($threadMessage->attachments as $attachment)
                                                        <li class="d-flex align-items-center justify-content-between gap-2 py-1">
                                                            <a
                                                                href="{{ route('tech.mail.attachments.download', ['placement' => $threadPlacement, 'attachment' => $attachment]) }}"
                                                                class="text-truncate"
                                                                title="Download {{ $attachment->filename ?: basename($attachment->path) }}">
                                                                <i class="bi bi-download me-1" aria-hidden="true"></i>{{ $attachment->filename ?: basename($attachment->path) }}
                                                            </a>
                                                            @if($attachment->size_bytes)
                                                                <span class="text-muted small flex-shrink-0">{{ number_format($attachment->size_bytes / 1024, 0) }} KB</span>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if($selectedPlacement->email_conversation_id)
                        <!-- Livewire teleports the optional Smart Inbox result region here, after the complete thread. -->
                        <div id="smart-inbox-review-results-slot" data-smart-inbox-results-slot></div>
                    @endif
                </div>
            @else
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-envelope-open fs-2 d-block mb-2" aria-hidden="true"></i>
                    Select a message to read it.
                </div>
            @endif
        </section>
    </div>
</div>
