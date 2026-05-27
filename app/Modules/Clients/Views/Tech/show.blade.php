{{--
    Client Show View

    This view displays the profile and summary information for a single client.
    When this page is loaded, the client is set as the "active client" in the session,
    affecting the context for sites, user_management, and documentation filters.
--}}
@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>{{ $client->name }}</h1>
        <x-buttons.back url="{{ route('tech.clients.index') }}" class="btn btn-sm btn-outline-secondary bi bi-arrow-left mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    @php
        $clientTasks = $clientTasks->sortByDesc('updated_at')->values();
        $missing = fn ($value) => filled($value) ? $value : '—';
    @endphp

    <div class="row">

        <!-- -------------------------------------------------------------------------------------------------- -->
        <!-- Client Summary -->
        <!-- -------------------------------------------------------------------------------------------------- -->
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Summary</span>
                    <a href="{{ route('tech.clients.settings.edit', $client->id) }}" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-gear"></i>
                    </a>
                </div>
                <div class="card-body">
                    <div class="row mb-0">
                        <dt class="col-sm-3">Name</dt><dd class="col-sm-9">{{ $client->name }}</dd>
                        <dt class="col-sm-3">Org No</dt><dd class="col-sm-9">{{ $client->org_no ?? '—' }}</dd>
                        <dt class="col-sm-3">Format</dt><dd class="col-sm-9">{{ $client->clientFormat?->name ?? '—' }}</dd>
                        <dt class="col-sm-3">Billing Email</dt><dd class="col-sm-9">{{ $client->billing_email ?? '—' }}</dd>
                        <dt class="col-sm-3">Status</dt><dd class="col-sm-9">@if($client->active)<span class="badge bg-success">Active</span>@else<span class="badge bg-secondary">Inactive</span>@endif</dd>
                        @php
                            $rmmIntegration = \App\Models\System\Integrations\Integration::where('type', 'rmm')->first();
                        @endphp
                        @if($rmmIntegration && $rmmIntegration->status === 'active')
                            <dt class="col-sm-3">N-able RMM</dt>
                            <dd class="col-sm-9">
                                @php
                                    $clientLink = $client->rmmLinks()->where('integration_id', $rmmIntegration->id)->first();
                                @endphp
                                @if($clientLink)
                                    <span class="badge bg-success">Active (ID: {{ $clientLink->external_id }})</span>
                                @else
                                    <span class="badge bg-warning text-dark">Not Linked</span>
                                @endif
                            </dd>
                        @endif
                        <dt class="col-sm-3">Notes</dt><dd class="col-sm-9">{{ $client->notes ?? '—' }}</dd>
                    </div>
                </div>
            </div>
        </div>

        <!-- -------------------------------------------------------------------------------------------------- -->
        <!-- Related Client Workspace Tabs -->
        <!-- -------------------------------------------------------------------------------------------------- -->
        <div class="col-12">
            <ul class="nav nav-tabs border-bottom border-secondary-subtle" id="clientWorkspaceTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active text-body border border-bottom-0" id="client-assets-tab" data-bs-toggle="tab" data-bs-target="#client-assets-pane" type="button" role="tab" aria-controls="client-assets-pane" aria-selected="true">
                        Assets
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link text-body border border-bottom-0" id="client-sites-tab" data-bs-toggle="tab" data-bs-target="#client-sites-pane" type="button" role="tab" aria-controls="client-sites-pane" aria-selected="false">
                        Sites <span class="badge text-bg-light border ms-1">{{ $client->sites->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link text-body border border-bottom-0" id="client-contracts-tab" data-bs-toggle="tab" data-bs-target="#client-contracts-pane" type="button" role="tab" aria-controls="client-contracts-pane" aria-selected="false">
                        Contracts <span class="badge text-bg-light border ms-1">{{ $contracts->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link text-body border border-bottom-0" id="client-tasks-tab" data-bs-toggle="tab" data-bs-target="#client-tasks-pane" type="button" role="tab" aria-controls="client-tasks-pane" aria-selected="false">
                        Tasks <span class="badge text-bg-light border ms-1">{{ $clientTasks->count() }}</span>
                    </button>
                </li>
            </ul>

            <div class="tab-content pt-3" id="clientWorkspaceTabsContent">
                <div class="tab-pane fade show active" id="client-assets-pane" role="tabpanel" aria-labelledby="client-assets-tab" tabindex="0">
                    <x-tech.assets.list-card :client="$client" />
                </div>

                <div class="tab-pane fade" id="client-sites-pane" role="tabpanel" aria-labelledby="client-sites-tab" tabindex="0">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-semibold">Sites</span>
                                <span class="badge text-bg-light border">{{ $client->sites->count() }}</span>
                            </div>
                            <x-buttons.addlink url="{{ route('tech.clients.sites.create', $client->id) }}" class="mb-0">New Site</x-buttons.addlink>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Address</th>
                                        <th>City</th>
                                        <th>Country</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($client->sites->sortBy('name') as $site)
                                        <tr class="cursor-pointer" data-href="{{ route('tech.clients.sites.show', $site) }}" onclick="window.location.href = this.dataset.href">
                                            <td>
                                                <a href="{{ route('tech.clients.sites.show', $site) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                                    {{ $site->name }}
                                                </a>
                                            </td>
                                            <td class="{{ blank($site->address) ? 'text-muted' : '' }}">{{ $missing($site->address) }}</td>
                                            <td class="{{ blank($site->city) ? 'text-muted' : '' }}">{{ $missing($site->city) }}</td>
                                            <td class="{{ blank($site->country) ? 'text-muted' : '' }}">{{ $missing($site->country) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No sites registered for this client.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="client-contracts-pane" role="tabpanel" aria-labelledby="client-contracts-tab" tabindex="0">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-semibold">Contracts</span>
                                <span class="badge text-bg-light border">{{ $contracts->count() }}</span>
                            </div>
                            <x-buttons.addlink url="{{ route('tech.contracts.create', ['client_id' => $client->id]) }}" class="mb-0">New Contract</x-buttons.addlink>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Contract</th>
                                        <th>Status</th>
                                        <th>Start</th>
                                        <th>End</th>
                                        <th>Items</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($contracts as $contract)
                                        <tr class="cursor-pointer" data-href="{{ route('tech.contracts.show', $contract) }}" onclick="window.location.href = this.dataset.href">
                                            <td>
                                                <a href="{{ route('tech.contracts.show', $contract) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                                    Contract #{{ $contract->id }}
                                                </a>
                                                @if(filled($contract->description))
                                                    <div class="small text-muted">{{ \Illuminate\Support\Str::limit($contract->description, 90) }}</div>
                                                @endif
                                            </td>
                                            <td>{{ $contract->approval_status }}</td>
                                            <td>{{ $contract->start_date?->format('Y-m-d') ?? '—' }}</td>
                                            <td>{{ $contract->end_date?->format('Y-m-d') ?? '—' }}</td>
                                            <td>{{ $contract->items->count() }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">No contracts registered for this client.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="client-tasks-pane" role="tabpanel" aria-labelledby="client-tasks-tab" tabindex="0">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-semibold">Tasks</span>
                                <span class="badge text-bg-light border">{{ $clientTasks->count() }}</span>
                            </div>
                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#clientTaskQuickCreateModal">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                New Task
                            </button>
                        </div>
                        <div class="list-group list-group-flush">
                            @forelse($clientTasks as $task)
                                <button type="button" class="list-group-item list-group-item-action text-start" data-bs-toggle="modal" data-bs-target="#clientTaskQuickViewModal{{ $task->id }}">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="fw-semibold">{{ $task->title }}</span>
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
                                <div class="list-group-item text-center text-muted py-4">No tasks registered for this client.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @include('task::components.quick-create-modal', [
            'modalId' => 'clientTaskQuickCreateModal',
            'ownerModel' => $client,
            'assignees' => $technicians,
            'defaultAssigneeId' => null,
            'returnTo' => route('tech.clients.show', $client),
        ])

        @foreach($clientTasks as $task)
            @include('task::components.quick-view-modal', [
                'modalId' => 'clientTaskQuickViewModal'.$task->id,
                'task' => $task,
                'assignees' => $technicians,
            ])
        @endforeach

    </div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" title="Client workspace" />
    @endif
@endsection

@section('rightbar')
    {{--
        Risk Analysis Summary Widget
        This widget provides a high-level overview of the client's current risk status.
        It displays the aggregated risk score and highlights the top 3 most critical risks
        identified across all assessments.
    --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center bg-light">
            <span class="fw-bold text-uppercase small opacity-75">Risk Overview</span>
            @if($client->risk_score !== null)
                <span class="badge {{ $client->risk_score_badge_class }}">
                    Score: {{ $client->risk_score }}
                </span>
            @else
                <span class="badge text-bg-secondary opacity-50">N/A</span>
            @endif
        </div>
        <div class="card-body p-0">
            @php
                $topRisks = $client->top_risks;
            @endphp

            @if($topRisks->count() > 0)
                <div class="list-group list-group-flush">
                    @foreach($topRisks as $risk)
                        {{--
                            Individual Risk Item Link
                            Each item links directly to its detailed update/history page
                            to allow quick action on high-priority risks.
                        --}}
                        <a href="{{ route('tech.risk.items.show', $risk->id) }}" class="list-group-item list-group-item-action p-3">
                            <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                <h6 class="mb-0 text-truncate fw-bold" style="max-width: 150px;">{{ $risk->title }}</h6>
                                <span class="badge {{ $risk->score_badge_class }}">{{ $risk->score }}</span>
                            </div>
                            <small class="text-muted d-block text-truncate opacity-75">{{ $risk->description }}</small>
                            <div class="mt-2">
                                <span class="badge bg-light text-dark border small">Status: {{ ucfirst($risk->status) }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="p-4 text-center text-muted">
                    <i class="fas fa-shield-alt fa-2x mb-2 d-block opacity-25"></i>
                    <small>No risk assessments found for this client.</small>
                </div>
            @endif
        </div>
        @if($topRisks->count() > 0)
            <div class="card-footer bg-white border-top-0 py-2">
                <a href="{{ route('tech.risk.index', ['active_client_id' => $client->id]) }}" class="small text-decoration-none fw-bold">
                    View All Assessments &rarr;
                </a>
            </div>
        @endif
    </div>

@endsection
