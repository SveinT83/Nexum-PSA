<div class="row g-3">
    <input type="hidden" name="client_id" value="{{ $clientId }}">
    <input type="hidden" name="site_id" value="{{ $siteId }}">
    @if($ticketId)
        <input type="hidden" name="owner_type" value="{{ $ticketMorphClass }}">
        <input type="hidden" name="owner_id" value="{{ $ticketId }}">
    @endif

    <div class="col-lg-4 position-relative">
        <label class="form-label" for="task_client_search">Client</label>
        <input
            type="search"
            class="form-control"
            id="task_client_search"
            wire:key="task-client-search-{{ $clientId ?? 'none' }}"
            wire:focus="openClientPicker"
            wire:model.live.debounce.250ms="clientSearch"
            placeholder="Search client or leave blank for internal">
        @if($clientResults->isNotEmpty())
        <div class="dropdown-menu show w-100 mt-1 shadow-sm" style="max-height: 16rem; overflow-y: auto; z-index: 1050;">
            @foreach($clientResults as $client)
                <button type="button" class="dropdown-item small" wire:key="task-client-result-{{ $client->id }}" wire:mousedown.prevent="selectClient({{ $client->id }})">
                    {{ $client->name }}
                </button>
            @endforeach
        </div>
        @endif
    </div>

    <div class="col-lg-4 position-relative">
        <label class="form-label" for="task_site_search">Site</label>
        <input
            type="search"
            class="form-control"
            id="task_site_search"
            wire:key="task-site-search-{{ $siteId ?? 'none' }}"
            wire:focus="openSitePicker"
            wire:model.live.debounce.250ms="siteSearch"
            placeholder="Search site">
        @if($siteResults->isNotEmpty())
        <div class="dropdown-menu show w-100 mt-1 shadow-sm" style="max-height: 16rem; overflow-y: auto; z-index: 1050;">
            @foreach($siteResults as $site)
                <button type="button" class="dropdown-item small" wire:key="task-site-result-{{ $site->id }}" wire:mousedown.prevent="selectSite({{ $site->id }})">
                    <span class="fw-semibold">{{ $site->name }}</span>
                    <span class="text-muted">{{ $site->client?->name }}</span>
                </button>
            @endforeach
        </div>
        @endif
    </div>

    <div class="col-lg-4 position-relative">
        <div class="d-flex justify-content-between align-items-center">
            <label class="form-label" for="task_ticket_search">Ticket</label>
            @if($ticketId)
                <button type="button" class="btn btn-sm btn-link p-0" wire:click="clearTicket">Clear</button>
            @endif
        </div>
        <input
            type="search"
            class="form-control"
            id="task_ticket_search"
            wire:key="task-ticket-search-{{ $ticketId ?? 'none' }}"
            wire:focus="openTicketPicker"
            wire:click="openTicketPicker"
            wire:model.live.debounce.250ms="ticketSearch"
            placeholder="Search ticket key or subject">
        @if($ticketResults->isNotEmpty())
        <div class="dropdown-menu show w-100 mt-1 shadow-sm" style="max-height: 16rem; overflow-y: auto; z-index: 1050;">
            @foreach($ticketResults as $ticket)
                <button type="button" class="dropdown-item small" wire:key="task-ticket-result-{{ $ticket->id }}" wire:mousedown.prevent="selectTicket({{ $ticket->id }})">
                    <span class="fw-semibold">{{ $ticket->ticket_key }}</span>
                    <span>{{ $ticket->subject }}</span>
                    <span class="d-block text-muted">
                        {{ $ticket->client?->name ?? ($ticket->workContext?->isInternal() ? 'Internal' : 'Unscoped') }}{{ $ticket->site ? ' / '.$ticket->site->name : '' }}
                    </span>
                </button>
            @endforeach
        </div>
        @endif
    </div>

    <div class="col-md-4">
        <label class="form-label" for="estimated_minutes">Estimated minutes</label>
        <input type="number" min="1" class="form-control" id="estimated_minutes" name="estimated_minutes" wire:model="estimatedMinutes">
    </div>

    @if($ticketId)
        <div class="col-md-8">
            <label class="form-label" for="ticket_rate_key">Rate</label>
            <select class="form-select" id="ticket_rate_key" name="ticket_rate_key" wire:model="ticketRateKey" @disabled($ticketRateOptions->isEmpty())>
                <option value="">Select rate</option>
                @foreach($ticketRateOptions as $rateOption)
                    <option value="{{ $rateOption['key'] }}">{{ $rateOption['label'] }} - {{ $rateOption['description'] }}</option>
                @endforeach
            </select>
            @if($ticketRateOptions->isEmpty())
                <div class="form-text text-danger">No ticket time rates are available for this ticket yet.</div>
            @endif
        </div>
    @endif

    <div class="col-md-8">
        <label class="form-label" for="parent_id">Parent task</label>
        <select class="form-select" id="parent_id" name="parent_id" wire:model="parentId">
            <option value="">No parent task</option>
            @foreach($parentOptions as $parentTask)
                <option value="{{ $parentTask->id }}">
                    {{ $parentTask->title }}{{ $parentTask->status ? ' - '.$parentTask->status->name : '' }}
                </option>
            @endforeach
        </select>
        <div class="form-text">Options follow the selected ticket, site, or client.</div>
    </div>
</div>
