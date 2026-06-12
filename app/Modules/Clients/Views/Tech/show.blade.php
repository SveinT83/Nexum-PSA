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
        $contacts = ($contacts ?? collect())->sortBy('name')->values();
        $availableTabs = ['assets', 'sites', 'contacts', 'contracts', 'time-usage', 'signals', 'tasks', 'custom-fields'];
        $activeClientTab = in_array(request('tab'), $availableTabs, true) ? request('tab') : 'assets';
        $formatMinutes = function (int $minutes): string {
            $hours = intdiv($minutes, 60);
            $remainder = $minutes % 60;

            if ($hours > 0 && $remainder > 0) {
                return "{$hours}h {$remainder}m";
            }

            return $hours > 0 ? "{$hours}h" : "{$remainder}m";
        };
        if ($activeClientTab === 'custom-fields' && ($customFields ?? collect())->isEmpty()) {
            $activeClientTab = 'assets';
        }
        $formatCustomFieldValue = function ($value) {
            if (is_array($value)) {
                return $value === [] ? '—' : implode(', ', $value);
            }

            if (is_bool($value)) {
                return $value ? 'Yes' : 'No';
            }

            return filled($value) ? $value : '—';
        };
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
                    <button class="nav-link {{ $activeClientTab === 'assets' ? 'active ' : '' }}text-body border border-bottom-0" id="client-assets-tab" data-bs-toggle="tab" data-bs-target="#client-assets-pane" type="button" role="tab" aria-controls="client-assets-pane" aria-selected="{{ $activeClientTab === 'assets' ? 'true' : 'false' }}">
                        Assets
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $activeClientTab === 'sites' ? 'active ' : '' }}text-body border border-bottom-0" id="client-sites-tab" data-bs-toggle="tab" data-bs-target="#client-sites-pane" type="button" role="tab" aria-controls="client-sites-pane" aria-selected="{{ $activeClientTab === 'sites' ? 'true' : 'false' }}">
                        Sites <span class="badge text-bg-light border ms-1">{{ $client->sites->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $activeClientTab === 'contacts' ? 'active ' : '' }}text-body border border-bottom-0" id="client-contacts-tab" data-bs-toggle="tab" data-bs-target="#client-contacts-pane" type="button" role="tab" aria-controls="client-contacts-pane" aria-selected="{{ $activeClientTab === 'contacts' ? 'true' : 'false' }}">
                        Contacts <span class="badge text-bg-light border ms-1">{{ $contacts->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $activeClientTab === 'contracts' ? 'active ' : '' }}text-body border border-bottom-0" id="client-contracts-tab" data-bs-toggle="tab" data-bs-target="#client-contracts-pane" type="button" role="tab" aria-controls="client-contracts-pane" aria-selected="{{ $activeClientTab === 'contracts' ? 'true' : 'false' }}">
                        Contracts <span class="badge text-bg-light border ms-1">{{ $contracts->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $activeClientTab === 'time-usage' ? 'active ' : '' }}text-body border border-bottom-0" id="client-time-usage-tab" data-bs-toggle="tab" data-bs-target="#client-time-usage-pane" type="button" role="tab" aria-controls="client-time-usage-pane" aria-selected="{{ $activeClientTab === 'time-usage' ? 'true' : 'false' }}">
                        Time <span class="badge text-bg-light border ms-1">{{ $clientTimeUsageEntries->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $activeClientTab === 'signals' ? 'active ' : '' }}text-body border border-bottom-0" id="client-signals-tab" data-bs-toggle="tab" data-bs-target="#client-signals-pane" type="button" role="tab" aria-controls="client-signals-pane" aria-selected="{{ $activeClientTab === 'signals' ? 'true' : 'false' }}">
                        Signals <span class="badge text-bg-light border ms-1">{{ $signals->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $activeClientTab === 'tasks' ? 'active ' : '' }}text-body border border-bottom-0" id="client-tasks-tab" data-bs-toggle="tab" data-bs-target="#client-tasks-pane" type="button" role="tab" aria-controls="client-tasks-pane" aria-selected="{{ $activeClientTab === 'tasks' ? 'true' : 'false' }}">
                        Tasks <span class="badge text-bg-light border ms-1">{{ $clientTasks->count() }}</span>
                    </button>
                </li>
                @if(($customFields ?? collect())->isNotEmpty())
                    <li class="nav-item" role="presentation">
                        <button class="nav-link {{ $activeClientTab === 'custom-fields' ? 'active ' : '' }}text-body border border-bottom-0" id="client-custom-fields-tab" data-bs-toggle="tab" data-bs-target="#client-custom-fields-pane" type="button" role="tab" aria-controls="client-custom-fields-pane" aria-selected="{{ $activeClientTab === 'custom-fields' ? 'true' : 'false' }}">
                            Custom Fields <span class="badge text-bg-light border ms-1">{{ $customFields->count() }}</span>
                        </button>
                    </li>
                @endif
            </ul>

            <div class="tab-content pt-3" id="clientWorkspaceTabsContent">
                <div @class(['tab-pane fade', 'show active' => $activeClientTab === 'assets']) id="client-assets-pane" role="tabpanel" aria-labelledby="client-assets-tab" tabindex="0">
                    <x-tech.assets.list-card :client="$client" />
                </div>

                <div @class(['tab-pane fade', 'show active' => $activeClientTab === 'sites']) id="client-sites-pane" role="tabpanel" aria-labelledby="client-sites-tab" tabindex="0">
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

                <div @class(['tab-pane fade', 'show active' => $activeClientTab === 'contacts']) id="client-contacts-pane" role="tabpanel" aria-labelledby="client-contacts-tab" tabindex="0">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-semibold">Contacts</span>
                                <span class="badge text-bg-light border">{{ $contacts->count() }}</span>
                            </div>
                            <x-buttons.addlink url="{{ route('tech.clients.user.create', $client) }}" class="mb-0">New Contact</x-buttons.addlink>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Site</th>
                                        <th>Role</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($contacts as $contact)
                                        <tr class="cursor-pointer" data-href="{{ route('tech.clients.user.show', $contact) }}" onclick="window.location.href = this.dataset.href">
                                            <td>
                                                <a href="{{ route('tech.clients.user.show', $contact) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                                    {{ $contact->name }}
                                                </a>
                                                @if($contact->is_default_for_client)
                                                    <span class="badge text-bg-light border ms-1">Default</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($contact->site)
                                                    <a href="{{ route('tech.clients.sites.show', $contact->site) }}" class="text-decoration-none" onclick="event.stopPropagation()">
                                                        {{ $contact->site->name }}
                                                    </a>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="{{ blank($contact->role) ? 'text-muted' : '' }}">{{ $missing($contact->role) }}</td>
                                            <td class="{{ blank($contact->email) ? 'text-muted' : '' }}">
                                                @if(filled($contact->email))
                                                    <a href="mailto:{{ $contact->email }}" onclick="event.stopPropagation()">{{ $contact->email }}</a>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="{{ blank($contact->phone) ? 'text-muted' : '' }}">
                                                @if(filled($contact->phone))
                                                    <a href="tel:{{ $contact->phone }}" onclick="event.stopPropagation()">{{ $contact->phone }}</a>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td>
                                                @if($contact->active)
                                                    <span class="badge bg-success">Active</span>
                                                @else
                                                    <span class="badge bg-secondary">Inactive</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">No contacts registered for this client.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div @class(['tab-pane fade', 'show active' => $activeClientTab === 'contracts']) id="client-contracts-pane" role="tabpanel" aria-labelledby="client-contracts-tab" tabindex="0">
                    @if($canViewTimebank)
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-semibold">Contract Timebank</span>
                                    <span class="badge text-bg-light border">{{ $clientTimebankBalances->count() }}</span>
                                </div>
                                @unless($quickTimebankPolicy['quick_timebank_enabled'])
                                    <span class="badge text-bg-secondary">Quick registration disabled</span>
                                @endunless
                            </div>
                            <div class="list-group list-group-flush">
                                @forelse($clientTimebankBalances as $timebank)
                                    <div class="list-group-item">
                                        <div>
                                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                                    <span class="fw-semibold">{{ $timebank['contract_item']->name }}</span>
                                                    <a href="{{ route('tech.contracts.show', $timebank['contract']) }}" class="small text-decoration-none">
                                                        Contract #{{ $timebank['contract']->id }}
                                                    </a>
                                                    <span class="badge text-bg-light border">
                                                        {{ $timebank['period_start']->format('Y-m-d') }} - {{ $timebank['period_end']->format('Y-m-d') }}
                                                    </span>
                                                    @if($timebank['overused_minutes'] > 0)
                                                        <span class="badge text-bg-danger">Overused {{ $formatMinutes($timebank['overused_minutes']) }}</span>
                                                    @else
                                                        <span class="badge text-bg-success">Remaining {{ $formatMinutes($timebank['remaining_minutes']) }}</span>
                                                    @endif
                                                </div>

                                                <div class="progress mb-2" style="height: 0.85rem;" role="progressbar" aria-label="Timebank usage" aria-valuenow="{{ $timebank['used_minutes'] }}" aria-valuemin="0" aria-valuemax="{{ max(1, $timebank['included_minutes']) }}">
                                                    <div class="progress-bar {{ $timebank['overused_minutes'] > 0 ? 'bg-danger' : 'bg-success' }}" style="width: {{ $timebank['usage_percent'] }}%"></div>
                                                </div>
                                                @if($timebank['overused_minutes'] > 0)
                                                    <div class="progress mb-2" style="height: 0.35rem;" role="progressbar" aria-label="Timebank overuse" aria-valuenow="{{ $timebank['overused_minutes'] }}" aria-valuemin="0" aria-valuemax="{{ max(1, $timebank['included_minutes']) }}">
                                                        <div class="progress-bar bg-danger" style="width: {{ max(6, $timebank['overuse_percent']) }}%"></div>
                                                    </div>
                                                @endif

                                                <div class="row g-2 small">
                                                    <div class="col-6 col-md-3">
                                                        <span class="text-muted">Included</span>
                                                        <div class="fw-semibold">{{ $formatMinutes($timebank['included_minutes']) }}</div>
                                                    </div>
                                                    <div class="col-6 col-md-3">
                                                        <span class="text-muted">Used</span>
                                                        <div class="fw-semibold">{{ $formatMinutes($timebank['used_minutes']) }}</div>
                                                    </div>
                                                    <div class="col-6 col-md-3">
                                                        <span class="text-muted">Remaining</span>
                                                        <div class="fw-semibold">{{ $formatMinutes($timebank['remaining_minutes']) }}</div>
                                                    </div>
                                                    <div class="col-6 col-md-3">
                                                        <span class="text-muted">Overused</span>
                                                        <div class="fw-semibold {{ $timebank['overused_minutes'] > 0 ? 'text-danger' : '' }}">{{ $formatMinutes($timebank['overused_minutes']) }}</div>
                                                    </div>
                                                </div>

                                        </div>
                                    </div>
                                @empty
                                    <div class="list-group-item text-center text-muted py-4">No active contract timebank found for this client.</div>
                                @endforelse
                            </div>
                        </div>

                    @endif

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

                <div @class(['tab-pane fade', 'show active' => $activeClientTab === 'time-usage']) id="client-time-usage-pane" role="tabpanel" aria-labelledby="client-time-usage-tab" tabindex="0">
                    @if($canViewTimebank && $quickTimebankPolicy['quick_timebank_enabled'] && $canQuickConsumeTimebank)
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-semibold">Quick Time Registration</span>
                                    <span class="badge text-bg-light border">{{ $clientTimebankBalances->count() }}</span>
                                </div>
                            </div>
                            <div class="list-group list-group-flush">
                                @forelse($clientTimebankBalances as $timebank)
                                    @php
                                        $canQuickUseLine = $timebank['time_rate_options']->isNotEmpty()
                                            && (
                                                $timebank['remaining_minutes'] > 0
                                                || (! $quickTimebankPolicy['quick_timebank_require_remaining'] && $quickTimebankPolicy['quick_timebank_allow_overuse'] && $canOverconsumeTimebank)
                                            );
                                        $modalId = 'quickTimebankConsumptionModal'.$timebank['contract_item']->id;
                                    @endphp
                                    <div class="list-group-item d-flex flex-column flex-lg-row justify-content-between gap-2">
                                        <div>
                                            <div class="fw-semibold">{{ $timebank['contract_item']->name }}</div>
                                            <div class="small text-muted">
                                                Remaining {{ $formatMinutes($timebank['remaining_minutes']) }} / Included {{ $formatMinutes($timebank['included_minutes']) }}
                                                @if($timebank['overused_minutes'] > 0)
                                                    <span class="text-danger ms-1">Overused {{ $formatMinutes($timebank['overused_minutes']) }}</span>
                                                @endif
                                            </div>
                                            @if($timebank['time_rate_options']->isEmpty())
                                                <div class="small text-danger">No contract time rate available.</div>
                                            @endif
                                        </div>
                                        <div class="d-flex align-items-start">
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}" @disabled(! $canQuickUseLine)>
                                                <i class="bi bi-clock-history" aria-hidden="true"></i>
                                                Register time
                                            </button>
                                        </div>
                                    </div>

                                    <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <form method="post" action="{{ route('tech.clients.contracts.timebank-consumptions.store', $client) }}" class="modal-content">
                                                @csrf
                                                <input type="hidden" name="contract_item_id" value="{{ $timebank['contract_item']->id }}">
                                                <div class="modal-header">
                                                    <h2 class="modal-title fs-6" id="{{ $modalId }}Label">Register Time</h2>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <div class="fw-semibold">{{ $timebank['contract_item']->name }}</div>
                                                        <div class="small text-muted">Contract #{{ $timebank['contract']->id }} · Remaining {{ $formatMinutes($timebank['remaining_minutes']) }}</div>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="time_rate_source_{{ $timebank['contract_item']->id }}" class="form-label">Time rate</label>
                                                        <select name="time_rate_source" id="time_rate_source_{{ $timebank['contract_item']->id }}" class="form-select" required>
                                                            @foreach($timebank['time_rate_options'] as $rateOption)
                                                                <option value="{{ $rateOption['value'] }}" @selected(old('time_rate_source') === $rateOption['value'])>{{ $rateOption['label'] }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="row g-3">
                                                        <div class="col-sm-6">
                                                            <label for="work_date_{{ $timebank['contract_item']->id }}" class="form-label">Work date</label>
                                                            <input type="date" name="work_date" id="work_date_{{ $timebank['contract_item']->id }}" value="{{ old('work_date', now()->toDateString()) }}" class="form-control" required>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <label for="minutes_{{ $timebank['contract_item']->id }}" class="form-label">Minutes</label>
                                                            <input type="number" name="minutes" id="minutes_{{ $timebank['contract_item']->id }}" value="{{ old('minutes') }}" min="1" max="{{ $quickTimebankPolicy['quick_timebank_max_minutes'] }}" class="form-control" required>
                                                        </div>
                                                        <div class="col-12">
                                                            <label for="note_{{ $timebank['contract_item']->id }}" class="form-label">Note</label>
                                                            <textarea name="note" id="note_{{ $timebank['contract_item']->id }}" rows="3" class="form-control" @required($quickTimebankPolicy['quick_timebank_require_note'])>{{ old('note') }}</textarea>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                                                        Register time
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                @empty
                                    <div class="list-group-item text-center text-muted py-4">No active contract timebank found for quick registration.</div>
                                @endforelse
                            </div>
                        </div>
                    @endif

                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-semibold">Time Usage</span>
                                <span class="badge text-bg-light border">{{ $clientTimeUsageEntries->count() }}</span>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Source</th>
                                        <th>Context</th>
                                        <th>Technician</th>
                                        <th>Minutes</th>
                                        <th>Rate</th>
                                        <th>Note</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($clientTimeUsageEntries as $usage)
                                        @php
                                            $usageModalId = 'clientTimeUsageEditModal'.$usage['source'].$usage['id'];
                                        @endphp
                                        <tr>
                                            <td>{{ $usage['work_date']?->format('Y-m-d') ?? '—' }}</td>
                                            <td><span class="badge text-bg-light border">{{ $usage['label'] }}</span></td>
                                            <td><a href="{{ $usage['context_url'] }}" class="text-decoration-none">{{ \Illuminate\Support\Str::limit($usage['context'], 70) }}</a></td>
                                            <td>{{ $usage['user']?->name ?? 'Unknown' }}</td>
                                            <td>{{ $formatMinutes((int) $usage['minutes']) }}</td>
                                            <td>
                                                {{ $usage['rate_name'] ?? '—' }}
                                                @if($usage['overused_minutes'])
                                                    <span class="badge text-bg-danger ms-1">Overuse {{ $formatMinutes((int) $usage['overused_minutes']) }}</span>
                                                @endif
                                            </td>
                                            <td class="{{ blank($usage['note'] ?? $usage['invoice_text']) ? 'text-muted' : '' }}">{{ \Illuminate\Support\Str::limit($usage['note'] ?: $usage['invoice_text'] ?: '—', 80) }}</td>
                                            <td class="text-end">
                                                @if($usage['ordered'])
                                                    <span class="badge text-bg-secondary">Ordered</span>
                                                @elseif($usage['can_edit'])
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#{{ $usageModalId }}">
                                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                                        Edit
                                                    </button>
                                                @else
                                                    <span class="text-muted small">Locked</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">No time usage registered for this client.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
	                            </table>
	                        </div>
	                    </div>

	                    @foreach($clientTimeUsageEntries as $usage)
	                        @php
	                            $usageModalId = 'clientTimeUsageEditModal'.$usage['source'].$usage['id'];
	                        @endphp
	                        @if($usage['can_edit'] && ! $usage['ordered'])
	                            <div class="modal fade" id="{{ $usageModalId }}" tabindex="-1" aria-labelledby="{{ $usageModalId }}Label" aria-hidden="true">
	                                <div class="modal-dialog">
	                                    <form method="post" action="{{ route('tech.clients.time-usage.update', [$client, $usage['source'], $usage['id']]) }}" class="modal-content">
	                                        @csrf
	                                        @method('PATCH')
	                                        <div class="modal-header">
	                                            <h2 class="modal-title fs-6" id="{{ $usageModalId }}Label">Edit Time Usage</h2>
	                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
	                                        </div>
	                                        <div class="modal-body">
	                                            <div class="mb-3">
	                                                <div class="fw-semibold">{{ $usage['context'] }}</div>
	                                                <div class="small text-muted">{{ $usage['label'] }} · {{ $usage['rate_name'] ?? 'No rate' }}</div>
	                                            </div>
	                                            <div class="row g-3">
	                                                @if($usage['rate_options']->isNotEmpty())
	                                                    <div class="col-12">
	                                                        <label class="form-label" for="usage_time_rate_source_{{ $usage['source'] }}_{{ $usage['id'] }}">Time rate</label>
	                                                        <select name="time_rate_source" id="usage_time_rate_source_{{ $usage['source'] }}_{{ $usage['id'] }}" class="form-select" required>
	                                                            @foreach($usage['rate_options'] as $rateOption)
	                                                                <option value="{{ $rateOption['value'] }}" @selected(old('time_rate_source', $usage['current_rate_source']) === $rateOption['value'])>{{ $rateOption['label'] }}</option>
	                                                            @endforeach
	                                                        </select>
	                                                    </div>
	                                                @endif
	                                                <div class="col-sm-6">
	                                                    <label class="form-label" for="usage_work_date_{{ $usage['source'] }}_{{ $usage['id'] }}">Work date</label>
	                                                    <input type="date" name="work_date" id="usage_work_date_{{ $usage['source'] }}_{{ $usage['id'] }}" value="{{ old('work_date', $usage['work_date']?->toDateString() ?? now()->toDateString()) }}" class="form-control" required>
	                                                </div>
	                                                <div class="col-sm-6">
	                                                    <label class="form-label" for="usage_minutes_{{ $usage['source'] }}_{{ $usage['id'] }}">Minutes</label>
	                                                    <input type="number" name="minutes" id="usage_minutes_{{ $usage['source'] }}_{{ $usage['id'] }}" value="{{ old('minutes', $usage['minutes']) }}" min="1" max="1440" class="form-control" required>
	                                                </div>
	                                                @if($usage['source'] === 'ticket')
	                                                    <div class="col-12">
	                                                        <label class="form-label" for="usage_invoice_text_{{ $usage['id'] }}">Invoice text</label>
	                                                        <textarea name="invoice_text" id="usage_invoice_text_{{ $usage['id'] }}" rows="2" class="form-control">{{ old('invoice_text', $usage['invoice_text']) }}</textarea>
	                                                    </div>
	                                                @endif
	                                                <div class="col-12">
	                                                    <label class="form-label" for="usage_note_{{ $usage['source'] }}_{{ $usage['id'] }}">Note</label>
	                                                    <textarea name="note" id="usage_note_{{ $usage['source'] }}_{{ $usage['id'] }}" rows="3" class="form-control">{{ old('note', $usage['note']) }}</textarea>
	                                                </div>
	                                            </div>
	                                        </div>
	                                        <div class="modal-footer">
	                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
	                                            <button type="submit" class="btn btn-sm btn-primary">
	                                                <i class="bi bi-check-lg" aria-hidden="true"></i>
	                                                Save
	                                            </button>
	                                        </div>
	                                    </form>
	                                </div>
	                            </div>
	                        @endif
	                    @endforeach
                </div>

                <div @class(['tab-pane fade', 'show active' => $activeClientTab === 'signals']) id="client-signals-pane" role="tabpanel" aria-labelledby="client-signals-tab" tabindex="0">
                    @include('signal::Tech.partials.related-signals', ['signals' => $signals])
                </div>

                <div @class(['tab-pane fade', 'show active' => $activeClientTab === 'tasks']) id="client-tasks-pane" role="tabpanel" aria-labelledby="client-tasks-tab" tabindex="0">
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

                @if(($customFields ?? collect())->isNotEmpty())
                    <div @class(['tab-pane fade', 'show active' => $activeClientTab === 'custom-fields']) id="client-custom-fields-pane" role="tabpanel" aria-labelledby="client-custom-fields-tab" tabindex="0">
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-semibold">Custom Fields</span>
                                    <span class="badge text-bg-light border">{{ $customFields->count() }}</span>
                                </div>
                                <span class="small text-muted">Visible client fields</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Field</th>
                                            <th>Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($customFields as $field)
                                            @php
                                                $modalId = 'clientCustomFieldValueModal'.$field['definition']->id;
                                                $canEditCustomField = (bool) $field['can_edit'];
                                            @endphp
                                            <tr @class(['cursor-pointer' => $canEditCustomField]) @if($canEditCustomField) data-bs-toggle="modal" data-bs-target="#{{ $modalId }}" @endif>
                                                <td>
                                                    <div class="fw-semibold">{{ $field['label'] }}</div>
                                                    @if(filled($field['help_text']))
                                                        <div class="small text-muted">{{ $field['help_text'] }}</div>
                                                    @endif
                                                </td>
                                                <td @class(['text-muted' => blank($field['value']) && $field['value'] !== false])>
                                                    {{ $formatCustomFieldValue($field['value']) }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @foreach(($customFields ?? collect()) as $field)
            @continue(! $field['can_edit'])
            @php $modalId = 'clientCustomFieldValueModal'.$field['definition']->id; @endphp
            <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
                <div class="modal-dialog">
                    <form method="POST" action="{{ route('tech.clients.custom-fields.update', [$client, $field['definition']]) }}" class="modal-content">
                        @csrf
                        @method('PATCH')
                        <div class="modal-header">
                            <h2 class="modal-title fs-5" id="{{ $modalId }}Label">{{ $field['label'] }}</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <label class="form-label" for="{{ $modalId }}Value">Value</label>
                            @include('customfield::components.value-input', [
                                'field' => $field,
                                'inputName' => 'value',
                                'inputId' => $modalId.'Value',
                            ])
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save value</button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach

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
