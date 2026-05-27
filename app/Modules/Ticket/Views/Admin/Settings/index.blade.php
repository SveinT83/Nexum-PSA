@extends('layouts.default_tech')

@section('title', 'Ticket Settings')

<!-- Page header: ticket administration lives inside the Ticket module, not routes/web.php or shared settings controllers. -->
@section('pageHeader')
    <h1>Ticket Settings</h1>
@endsection

@section('content')
    <!-- Flash messages: settings actions redirect back here after saving or blocking protected records. -->
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <!-- Email settings: ticket replies use the Email module account marked with the tickets default scope. -->
    <x-card.default title="Email">
        <form method="POST" action="{{ route('tech.admin.settings.tickets.default-email-account.update') }}">
            @csrf

            <div class="mb-3">
                <label for="email_account_id" class="form-label">Default outbound account</label>
                <select id="email_account_id" name="email_account_id" class="form-select @error('email_account_id') is-invalid @enderror">
                    <option value="">No default ticket account</option>
                    @foreach ($emailAccounts as $account)
                        <option value="{{ $account->id }}" @selected(old('email_account_id', $defaultTicketEmailAccount?->id) == $account->id)>
                            {{ $account->address }}@if ($account->description) - {{ $account->description }}@endif
                        </option>
                    @endforeach
                </select>
                @error('email_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">
                    This updates the same account default used by Email Settings for the <code>tickets</code> scope.
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save email settings</button>
        </form>
    </x-card.default>

    <!-- Ticket types: high-level classification used by manual creation and Ticket Rules. -->
    <x-card.default title="Ticket Types">
        <x-slot name="headerActions">
            <x-buttons.addButton data-bs-toggle="modal" data-bs-target="#createTicketTypeModal"> Add type</x-buttons.addButton>
        </x-slot>

        <div class="list-group list-group-flush">
            @forelse($types as $type)
                <button type="button" class="list-group-item list-group-item-action px-0" data-bs-toggle="modal" data-bs-target="#editTicketTypeModal{{ $type->id }}">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="fw-semibold">{{ $type->name }}</div>
                            <div class="small text-muted">
                                {{ $type->slug }}
                                @if($type->description)
                                    <span class="mx-1">·</span>{{ $type->description }}
                                @endif
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge {{ $type->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $type->is_active ? 'Active' : 'Disabled' }}</span>
                            @if($type->is_system)
                                <span class="badge text-bg-light">System</span>
                            @endif
                            <div class="small text-muted mt-1">{{ $type->tickets_count }} tickets</div>
                        </div>
                    </div>
                </button>
            @empty
                <div class="text-muted small">No ticket types configured.</div>
            @endforelse
        </div>
    </x-card.default>

    <!-- Queues: operational work buckets and future inbound email routing targets. -->
    <x-card.default title="Queues">
        <x-slot name="headerActions">
            <x-buttons.addButton data-bs-toggle="modal" data-bs-target="#createTicketQueueModal">
                Add queue
            </x-buttons.addButton>
        </x-slot>

        <div class="list-group list-group-flush">
            @forelse($queues as $queue)
                <button type="button" class="list-group-item list-group-item-action px-0" data-bs-toggle="modal" data-bs-target="#editTicketQueueModal{{ $queue->id }}">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="fw-semibold">{{ $queue->name }}</div>
                            <div class="small text-muted">
                                {{ $queue->slug }}
                                @if($queue->email_address)
                                    <span class="mx-1">·</span>{{ $queue->email_address }}
                                @endif
                                @if($queue->description)
                                    <span class="mx-1">·</span>{{ $queue->description }}
                                @endif
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge {{ $queue->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $queue->is_active ? 'Active' : 'Disabled' }}</span>
                            @if($queue->is_default)
                                <span class="badge text-bg-primary">Default</span>
                            @endif
                            <div class="small text-muted mt-1">{{ $queue->tickets_count }} tickets</div>
                        </div>
                    </div>
                </button>
            @empty
                <div class="text-muted small">No queues configured.</div>
            @endforelse
        </div>
    </x-card.default>

    <!-- Statuses: lifecycle labels used by ticket edits, close actions, and future workflows. -->
    <x-card.default title="Statuses">
        <x-slot name="headerActions">
            <x-buttons.addButton data-bs-toggle="modal" data-bs-target="#createTicketStatusModal">
                Add status
            </x-buttons.addButton>
        </x-slot>

        <div class="list-group list-group-flush">
            @forelse($statuses as $status)
                <button type="button" class="list-group-item list-group-item-action px-0" data-bs-toggle="modal" data-bs-target="#editTicketStatusModal{{ $status->id }}">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="fw-semibold">{{ $status->name }}</div>
                            <div class="small text-muted">{{ $status->slug }} - {{ ucfirst($status->state) }}</div>
                        </div>
                        <div class="text-end">
                            <span class="badge {{ $status->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $status->is_active ? 'Active' : 'Disabled' }}</span>
                            @if($status->is_default)
                                <span class="badge text-bg-primary">Default</span>
                            @endif
                            @if($status->is_closed)
                                <span class="badge text-bg-dark">Closed</span>
                            @endif
                            <div class="small text-muted mt-1">{{ $status->tickets_count }} tickets</div>
                        </div>
                    </div>
                </button>
            @empty
                <div class="text-muted small">No statuses configured.</div>
            @endforelse
        </div>
    </x-card.default>

    <!-- Priorities: level 1 is highest urgency; TicketIndexQuery also uses this value for priority sorting. -->
    <x-card.default title="Priorities">
        <x-slot name="headerActions">
            <x-buttons.addButton data-bs-toggle="modal" data-bs-target="#createTicketPriorityModal">
                Add priority
            </x-buttons.addButton>
        </x-slot>

        <div class="list-group list-group-flush">
            @forelse($priorities as $priority)
                <button type="button" class="list-group-item list-group-item-action px-0" data-bs-toggle="modal" data-bs-target="#editTicketPriorityModal{{ $priority->id }}">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="fw-semibold">P{{ $priority->level }} {{ $priority->name }}</div>
                            <div class="small text-muted">{{ $priority->slug }}</div>
                        </div>
                        <div class="text-end">
                            <span class="badge {{ $priority->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $priority->is_active ? 'Active' : 'Disabled' }}</span>
                            @if($priority->is_default)
                                <span class="badge text-bg-primary">Default</span>
                            @endif
                            <div class="small text-muted mt-1">{{ $priority->tickets_count }} tickets</div>
                        </div>
                    </div>
                </button>
            @empty
                <div class="text-muted small">No priorities configured.</div>
            @endforelse
        </div>
    </x-card.default>

    <!-- Type modals: create and edit forms reuse the same partial to keep field names aligned with controller validation. -->
    <div class="modal fade" id="createTicketTypeModal" tabindex="-1" aria-labelledby="createTicketTypeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('tech.admin.settings.tickets.types.store') }}" class="modal-content">
                @csrf
                <input type="hidden" name="is_active" value="0">
                <input type="hidden" name="is_deletable" value="0">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="createTicketTypeModalLabel">Add Ticket Type</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('ticket::Admin.Settings.partials.type-form', ['type' => null])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create type</button>
                </div>
            </form>
        </div>
    </div>

    @foreach($types as $type)
        <div class="modal fade" id="editTicketTypeModal{{ $type->id }}" tabindex="-1" aria-labelledby="editTicketTypeModalLabel{{ $type->id }}" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('tech.admin.settings.tickets.types.update', $type) }}" class="modal-content">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="is_active" value="0">
                    <input type="hidden" name="is_deletable" value="0">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editTicketTypeModalLabel{{ $type->id }}">Edit Ticket Type</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @include('ticket::Admin.Settings.partials.type-form', ['type' => $type])
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save type</button>
                        </div>
                    </div>
                </form>
                <form method="POST" action="{{ route('tech.admin.settings.tickets.types.destroy', $type) }}" class="modal-content border-0 bg-transparent mt-2" onsubmit="return confirm('Delete this ticket type?');">
                    @csrf
                    @method('DELETE')
                    <div class="text-end">
                        <button type="submit" class="btn btn-outline-danger btn-sm" @disabled(! $type->is_deletable || $type->tickets_count > 0)>Delete type</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    <!-- Queue modals: default selection is normalized server-side so only one queue remains default. -->
    <div class="modal fade" id="createTicketQueueModal" tabindex="-1" aria-labelledby="createTicketQueueModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('tech.admin.settings.tickets.queues.store') }}" class="modal-content">
                @csrf
                <input type="hidden" name="is_active" value="0">
                <input type="hidden" name="is_default" value="0">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="createTicketQueueModalLabel">Add Queue</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('ticket::Admin.Settings.partials.queue-form', ['queue' => null])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create queue</button>
                </div>
            </form>
        </div>
    </div>

    @foreach($queues as $queue)
        <div class="modal fade" id="editTicketQueueModal{{ $queue->id }}" tabindex="-1" aria-labelledby="editTicketQueueModalLabel{{ $queue->id }}" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('tech.admin.settings.tickets.queues.update', $queue) }}" class="modal-content">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="is_active" value="0">
                    <input type="hidden" name="is_default" value="0">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editTicketQueueModalLabel{{ $queue->id }}">Edit Queue</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @include('ticket::Admin.Settings.partials.queue-form', ['queue' => $queue])
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save queue</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('tech.admin.settings.tickets.queues.destroy', $queue) }}" class="modal-content border-0 bg-transparent mt-2" onsubmit="return confirm('Delete this queue?');">
                    @csrf
                    @method('DELETE')
                    <div class="text-end">
                        <button type="submit" class="btn btn-outline-danger btn-sm" @disabled($queue->tickets_count > 0)>Delete queue</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    <!-- Status modals: closed and default flags drive lifecycle behavior, so they are explicit controls. -->
    <div class="modal fade" id="createTicketStatusModal" tabindex="-1" aria-labelledby="createTicketStatusModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('tech.admin.settings.tickets.statuses.store') }}" class="modal-content">
                @csrf
                <input type="hidden" name="is_active" value="0">
                <input type="hidden" name="is_default" value="0">
                <input type="hidden" name="is_closed" value="0">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="createTicketStatusModalLabel">Add Status</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('ticket::Admin.Settings.partials.status-form', ['status' => null])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create status</button>
                </div>
            </form>
        </div>
    </div>

    @foreach($statuses as $status)
        <div class="modal fade" id="editTicketStatusModal{{ $status->id }}" tabindex="-1" aria-labelledby="editTicketStatusModalLabel{{ $status->id }}" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('tech.admin.settings.tickets.statuses.update', $status) }}" class="modal-content">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="is_active" value="0">
                    <input type="hidden" name="is_default" value="0">
                    <input type="hidden" name="is_closed" value="0">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editTicketStatusModalLabel{{ $status->id }}">Edit Status</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @include('ticket::Admin.Settings.partials.status-form', ['status' => $status])
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save status</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('tech.admin.settings.tickets.statuses.destroy', $status) }}" class="modal-content border-0 bg-transparent mt-2" onsubmit="return confirm('Delete this ticket status?');">
                    @csrf
                    @method('DELETE')
                    <div class="text-end">
                        <button type="submit" class="btn btn-outline-danger btn-sm" @disabled($status->tickets_count > 0)>Delete status</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    <!-- Priority modals: priorities may be referenced by Ticket Rules, so protected deletes are handled in the controller. -->
    <div class="modal fade" id="createTicketPriorityModal" tabindex="-1" aria-labelledby="createTicketPriorityModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('tech.admin.settings.tickets.priorities.store') }}" class="modal-content">
                @csrf
                <input type="hidden" name="is_active" value="0">
                <input type="hidden" name="is_default" value="0">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="createTicketPriorityModalLabel">Add Priority</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('ticket::Admin.Settings.partials.priority-form', ['priority' => null])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create priority</button>
                </div>
            </form>
        </div>
    </div>

    @foreach($priorities as $priority)
        <div class="modal fade" id="editTicketPriorityModal{{ $priority->id }}" tabindex="-1" aria-labelledby="editTicketPriorityModalLabel{{ $priority->id }}" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('tech.admin.settings.tickets.priorities.update', $priority) }}" class="modal-content">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="is_active" value="0">
                    <input type="hidden" name="is_default" value="0">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editTicketPriorityModalLabel{{ $priority->id }}">Edit Priority</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @include('ticket::Admin.Settings.partials.priority-form', ['priority' => $priority])
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save priority</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('tech.admin.settings.tickets.priorities.destroy', $priority) }}" class="modal-content border-0 bg-transparent mt-2" onsubmit="return confirm('Delete this ticket priority?');">
                    @csrf
                    @method('DELETE')
                    <div class="text-end">
                        <button type="submit" class="btn btn-outline-danger btn-sm" @disabled($priority->tickets_count > 0)>Delete priority</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach
@endsection

@section('sidebar')
    <x-nav.admin-menu group="tickets" />
@endsection

@section('rightbar')
    <!-- Operational note: outbound ticket email depends on the account chosen in the Email card. -->
    <x-card.default title="Email note">
        <p class="small text-muted mb-0">
            The selected account will be used as the default sender when ticket replies are sent by email.
        </p>
    </x-card.default>
@endsection
