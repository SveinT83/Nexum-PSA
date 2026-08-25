@extends('layouts.default_tech')

@section('title', 'AI Privacy & Coordinator Governance')

@section('pageHeader')
    <div class="row">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('tech.admin.index') }}">Admin</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.index') }}">Integrations</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.ai.index') }}">AI Integration</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Privacy & Coordinator</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center justify-content-between">
                <h1 class="h3 mb-0">AI Privacy & Coordinator Governance</h1>
                <div class="btn-group">
                    <a href="{{ route('tech.admin.system.integrations.ai.telemetry.index') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-chart-line mr-1"></i> Telemetry
                    </a>
                    <a href="{{ route('tech.admin.system.integrations.ai.rate-cards.index') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-list mr-1"></i> Rate Cards
                    </a>
                    <a href="{{ route('tech.admin.system.integrations.ai.index') }}" class="btn btn-outline-primary">
                        <i class="fas fa-cog mr-1"></i> AI Settings
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('content')
<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <!-- Installation maximum policy -->
    <form method="POST" action="{{ route('tech.admin.system.integrations.ai.privacy.policy.update') }}" class="card mb-4">
        @csrf @method('PUT')
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Installation maximum policy</strong>
            <span class="badge text-bg-secondary">Revision {{ $policy->revision }}</span>
        </div>
        <div class="card-body">
            <p class="text-muted">Every provider, workload and token can narrow this policy, but cannot widen it.</p>
            <div class="row g-3">
                @foreach([
                    'ai_enabled' => 'Enable AI processing',
                    'external_processing_enabled' => 'Enable external processing',
                    'privacy_gateway_enabled' => 'Enable privacy gateway',
                    'direct_external_enabled' => 'Enable approved direct external processing',
                    'retain_denials' => 'Retain denied access metadata',
                    'payload_retention_enabled' => 'Enable optional encrypted payload retention',
                    'employee_identification_allowed' => 'Allow documented employee identification',
                ] as $field => $label)
                    <div class="col-md-4"><div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1" id="{{ $field }}" @checked($policy->{$field})>
                        <label class="form-check-label" for="{{ $field }}">{{ $label }}</label>
                    </div></div>
                @endforeach
                <div class="col-md-6">
                    <label class="form-label">Allowed processing modes</label>
                    @foreach($processingModes as $mode)
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="allowed_processing_modes[]" value="{{ $mode }}" id="mode_{{ $mode }}" @checked(in_array($mode, $policy->allowed_processing_modes ?? [], true))><label class="form-check-label" for="mode_{{ $mode }}">{{ str_replace('_', ' ', ucfirst($mode)) }}</label></div>
                    @endforeach
                </div>
                <div class="col-md-3"><label class="form-label">Maximum data profile</label><select class="form-select" name="maximum_data_profile">@foreach($dataProfiles as $profile)<option value="{{ $profile }}" @selected($policy->maximum_data_profile === $profile)>{{ str_replace('_', ' ', ucfirst($profile)) }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Context scope</label><select class="form-select" name="context_scope">@foreach(['internal_only', 'selected_clients', 'selected_work_contexts'] as $scope)<option value="{{ $scope }}" @selected($policy->context_scope === $scope)>{{ str_replace('_', ' ', ucfirst($scope)) }}</option>@endforeach</select></div>
                @foreach([
                    'maximum_query_days' => ['Maximum query days', 1, 366],
                    'maximum_page_size' => ['Maximum page size', 1, 200],
                    'maximum_results' => ['Maximum results', 1, 5000],
                    'requests_per_minute' => ['Requests per minute', 1, 600],
                    'audit_retention_days' => ['Audit retention days', 30, 730],
                    'payload_retention_days' => ['Payload retention days', 1, 30],
                ] as $field => [$label, $min, $max])
                    <div class="col-md-2"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-control" type="number" min="{{ $min }}" max="{{ $max }}" id="{{ $field }}" name="{{ $field }}" value="{{ $policy->{$field} }}" required></div>
                @endforeach
                <div class="col-md-6"><label class="form-label">Coordination purpose</label><textarea class="form-control" name="coordination_purpose" rows="2">{{ $policy->coordination_purpose }}</textarea></div>
                <div class="col-md-6"><label class="form-label">Staff transparency reference</label><textarea class="form-control" name="staff_transparency_reference" rows="2">{{ $policy->staff_transparency_reference }}</textarea></div>
                <div class="col-md-3"><label class="form-label">Policy expires</label><input class="form-control" type="date" name="expires_at" value="{{ $policy->expires_at?->toDateString() }}"></div>
                <div class="col-md-9"><label class="form-label">Change reason</label><input class="form-control" name="change_reason" maxlength="500" required></div>
            </div>
        </div>
        <div class="card-footer text-end"><button class="btn btn-primary" type="submit">Save policy revision</button></div>
    </form>

    <!-- Provider governance -->
    <div class="card mb-4">
        <div class="card-header"><strong>Provider governance</strong></div>
        <div class="card-body">
            <p class="text-muted">Approval records are evidence entered by your organization; Nexum does not certify a provider's legal compliance.</p>
            <div class="accordion" id="providerGovernance">
                @forelse($providers as $provider)
                    @php($record = $governance->get($provider->id))
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#provider_{{ $provider->id }}">{{ $provider->name }} @if($record?->is_approved)<span class="badge text-bg-success ms-2">Approved</span>@else<span class="badge text-bg-secondary ms-2">Not approved</span>@endif</button></h2>
                        <div id="provider_{{ $provider->id }}" class="accordion-collapse collapse" data-bs-parent="#providerGovernance">
                            <form class="accordion-body" method="POST" action="{{ route('tech.admin.system.integrations.ai.privacy.providers.update', $provider) }}">
                                @csrf @method('PUT')
                                <div class="row g-3">
                                    <div class="col-md-6"><label class="form-label">Purpose</label><textarea class="form-control" name="purpose" required>{{ $record?->purpose }}</textarea></div>
                                    <div class="col-md-3"><label class="form-label">Recipient</label><input class="form-control" name="recipient_name" value="{{ $record?->recipient_name }}" required></div>
                                    <div class="col-md-3"><label class="form-label">Processing region</label><input class="form-control" name="processing_regions[]" value="{{ $record?->processing_regions[0] ?? '' }}" required></div>
                                    <div class="col-md-3"><label class="form-label">Support region</label><input class="form-control" name="support_regions[]" value="{{ $record?->support_regions[0] ?? '' }}"></div>
                                    <div class="col-md-3"><label class="form-label">DPA status</label><select class="form-select" name="dpa_status">@foreach(['not_reviewed', 'approved', 'rejected'] as $status)<option @selected($record?->dpa_status === $status)>{{ $status }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><label class="form-label">DPA reference</label><input class="form-control" name="dpa_reference" value="{{ $record?->dpa_reference }}"></div>
                                    <div class="col-md-3"><label class="form-label">DPIA decision</label><select class="form-select" name="dpia_status">@foreach(['required', 'completed', 'not_required', 'rejected'] as $status)<option @selected($record?->dpia_status === $status)>{{ $status }}</option>@endforeach</select></div>
                                    @foreach(['subprocessor_notes' => 'Subprocessors', 'transfer_assessment' => 'Transfer assessment', 'retention_declaration' => 'Retention declaration', 'training_declaration' => 'Training declaration', 'dpia_rationale' => 'DPIA rationale'] as $field => $label)
                                        <div class="col-md-6"><label class="form-label">{{ $label }}</label><textarea class="form-control" name="{{ $field }}" required>{{ $record?->{$field} }}</textarea></div>
                                    @endforeach
                                    <div class="col-md-4"><label class="form-label">Allowed modes</label>@foreach($processingModes as $mode)<div class="form-check"><input class="form-check-input" type="checkbox" name="allowed_processing_modes[]" value="{{ $mode }}" @checked(in_array($mode, $record?->allowed_processing_modes ?? [], true))><label class="form-check-label">{{ $mode }}</label></div>@endforeach</div>
                                    <div class="col-md-3"><label class="form-label">Maximum profile</label><select class="form-select" name="maximum_data_profile">@foreach($dataProfiles as $profile)<option value="{{ $profile }}" @selected($record?->maximum_data_profile === $profile)>{{ $profile }}</option>@endforeach</select></div>
                                    <div class="col-md-2"><label class="form-label">Expires</label><input class="form-control" type="date" name="expires_at" value="{{ $record?->expires_at?->toDateString() }}"></div>
                                    <div class="col-md-3 pt-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_approved" value="1" @checked($record?->is_approved)><label class="form-check-label">Approved</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($record?->is_active)><label class="form-check-label">Active</label></div></div>
                                </div>
                                <div class="text-end mt-3"><button class="btn btn-primary">Save governance review</button></div>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="mb-0">Create an AI provider before recording governance.</p>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Model and agent policies -->
    <div class="row g-4 mb-4">
        <div class="col-xl-6"><div class="card h-100"><div class="card-header"><strong>Model policies</strong></div><div class="card-body">
            @forelse($providers as $provider)
                @php($defaultModelPolicy = ($modelPolicies->get($provider->id) ?? collect())->firstWhere('model', $provider->default_model))
                <form class="border rounded p-3 mb-3" method="POST" action="{{ route('tech.admin.system.integrations.ai.privacy.models.update', $provider) }}">@csrf @method('PUT')
                    <div class="fw-semibold mb-2">{{ $provider->name }}</div><div class="row g-2">
                        <div class="col-12"><label class="form-label">Model</label><input class="form-control" name="model" value="{{ $defaultModelPolicy?->model ?? $provider->default_model }}" required></div>
                        <div class="col-md-5"><label class="form-label">Processing mode</label><select class="form-select" name="processing_mode">@foreach($processingModes as $mode)<option value="{{ $mode }}" @selected($defaultModelPolicy?->processing_mode === $mode)>{{ $mode }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Maximum profile</label><select class="form-select" name="maximum_data_profile">@foreach($dataProfiles as $profile)<option value="{{ $profile }}" @selected($defaultModelPolicy?->maximum_data_profile === $profile)>{{ $profile }}</option>@endforeach</select></div>
                        <div class="col-md-3"><label class="form-label">Expires</label><input class="form-control" type="date" name="expires_at" value="{{ $defaultModelPolicy?->expires_at?->toDateString() }}"></div>
                        <div class="col-12 d-flex justify-content-between align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_approved" value="1" @checked($defaultModelPolicy?->is_approved)><label class="form-check-label">Approved</label></div><button class="btn btn-sm btn-primary">Save model policy</button></div>
                    </div>
                </form>
            @empty<p class="mb-0 text-muted">No providers configured.</p>@endforelse
        </div></div></div>
        <div class="col-xl-6"><div class="card h-100"><div class="card-header"><strong>Agent overrides</strong></div><div class="card-body">
            <p class="small text-muted">An agent can only use the same processing route and an equal or narrower data profile than its approved model.</p>
            @forelse($agents as $agent)
                @php($agentPolicy = $agentPolicies->get($agent->id))
                <form class="border rounded p-3 mb-3" method="POST" action="{{ route('tech.admin.system.integrations.ai.privacy.agents.update', $agent) }}">@csrf @method('PUT')
                    <div class="fw-semibold mb-2">{{ $agent->name }} <span class="small text-muted">{{ $agent->provider?->name }} / {{ $agent->model ?: $agent->provider?->default_model }}</span></div><div class="row g-2">
                        <div class="col-md-5"><label class="form-label">Processing mode</label><select class="form-select" name="processing_mode">@foreach($processingModes as $mode)<option value="{{ $mode }}" @selected($agentPolicy?->processing_mode === $mode)>{{ $mode }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Maximum profile</label><select class="form-select" name="maximum_data_profile">@foreach($dataProfiles as $profile)<option value="{{ $profile }}" @selected($agentPolicy?->maximum_data_profile === $profile)>{{ $profile }}</option>@endforeach</select></div>
                        <div class="col-md-3"><label class="form-label">Expires</label><input class="form-control" type="date" name="expires_at" value="{{ $agentPolicy?->expires_at?->toDateString() }}"></div>
                        <div class="col-12 d-flex justify-content-between align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_approved" value="1" @checked($agentPolicy?->is_approved)><label class="form-check-label">Approved</label></div><button class="btn btn-sm btn-primary">Save agent policy</button></div>
                    </div>
                </form>
            @empty<p class="mb-0 text-muted">No AI agents configured.</p>@endforelse
        </div></div></div>
    </div>

    <!-- Internal structured workloads -->
    <div class="card mb-4">
        <div class="card-header"><strong>Create approved internal model workload</strong></div>
        <form method="POST" action="{{ route('tech.admin.system.integrations.ai.privacy.workloads.internal.store') }}">
            @csrf
            <div class="card-body">
                <p class="text-muted small">For supplier-order extraction and other governed server-side jobs. The selected agent must be active, non-writing, and have no tools, data sources, or API scopes.</p>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
                    <div class="col-md-8"><label class="form-label">Purpose</label><input class="form-control" name="purpose" required></div>
                    <div class="col-md-4"><label class="form-label">Approved non-writing agent</label><select class="form-select" name="ai_agent_id" required><option value="">Select agent</option>@foreach($agents as $agent)<option value="{{ $agent->id }}">{{ $agent->name }} · {{ $agent->provider?->name ?? 'No provider' }} · {{ $agent->model ?: $agent->provider?->default_model }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label">Mode</label><select class="form-select" name="processing_mode">@foreach($processingModes as $mode)<option value="{{ $mode }}">{{ $mode }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label">Maximum profile</label><select class="form-select" name="maximum_data_profile">@foreach($dataProfiles as $profile)<option value="{{ $profile }}">{{ $profile }}</option>@endforeach</select></div>
                    <div class="col-md-2"><label class="form-label">Approval expires</label><input class="form-control" type="date" name="expires_at" required></div>
                </div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary">Create internal workload</button></div>
        </form>
    </div>

    <!-- Coordinator workloads and tokens -->
    <div class="row g-4 mb-4">
        <div class="col-xl-5"><form class="card h-100" method="POST" action="{{ route('tech.admin.system.integrations.ai.privacy.workloads.store') }}">
            @csrf
            <div class="card-header"><strong>Create approved coordinator workload</strong></div>
            <div class="card-body"><div class="row g-3">
                <div class="col-12"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
                <div class="col-12"><label class="form-label">Purpose</label><textarea class="form-control" name="purpose" required></textarea></div>
                <div class="col-md-6"><label class="form-label">Approved provider for external modes</label><select class="form-select" name="ai_provider_id"><option value="">None (local only)</option>@foreach($providers as $provider)<option value="{{ $provider->id }}">{{ $provider->name }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label">Approved model for external modes</label><input class="form-control" name="model" placeholder="Exact model identifier"></div>
                <div class="col-md-6"><label class="form-label">Mode</label><select class="form-select" name="processing_mode">@foreach($processingModes as $mode)<option value="{{ $mode }}">{{ $mode }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label">Maximum profile</label><select class="form-select" name="maximum_data_profile">@foreach($dataProfiles as $profile)<option value="{{ $profile }}">{{ $profile }}</option>@endforeach</select></div>
                <div class="col-12"><label class="form-label">Read abilities</label><div class="border rounded p-2" style="max-height: 15rem; overflow:auto">@foreach($coordinatorAbilities as $ability => $details)<div class="form-check"><input class="form-check-input" type="checkbox" name="abilities[]" value="{{ $ability }}" id="workload_{{ str_replace('.', '_', $ability) }}"><label class="form-check-label" for="workload_{{ str_replace('.', '_', $ability) }}">{{ $details['label'] }}</label></div>@endforeach</div></div>
                <div class="col-12"><label class="form-label">Approval expires</label><input class="form-control" type="date" name="expires_at" required></div>
            </div></div><div class="card-footer text-end"><button class="btn btn-primary">Create workload</button></div>
        </form></div>
        <div class="col-xl-7"><div class="card h-100"><div class="card-header"><strong>Workloads and bound tokens</strong></div><div class="card-body">
            @forelse($workloads as $workload)
                <div class="border rounded p-3 mb-3"><div class="d-flex justify-content-between"><div><strong>{{ $workload->name }}</strong><div class="small text-muted">{{ $workload->purpose }}</div></div><span class="badge text-bg-{{ $workload->is_active ? 'success' : 'secondary' }}">{{ $workload->is_active ? 'Active' : 'Inactive' }}</span></div>
                    <div class="small mt-2">{{ $workload->workload_type ?? 'coordinator_api' }} · {{ $workload->processing_mode }} · {{ $workload->maximum_data_profile }}@if($workload->supportsCoordinatorTokens()) · {{ implode(', ', $workload->abilities ?? []) }}@endif</div>
                    @if($workload->supportsCoordinatorTokens())
                    <form class="row g-2 mt-2" method="POST" action="{{ route('tech.admin.system.integrations.ai.privacy.workloads.tokens.store', $workload) }}">@csrf
                        <div class="col-md-4"><input class="form-control" name="name" placeholder="Token name" required></div><div class="col-md-3"><input class="form-control" type="date" name="expires_at" required></div><div class="col-md-2"><input class="form-control" type="number" name="requests_per_minute" value="30" min="1" max="600" required></div><div class="col-md-3"><button class="btn btn-outline-primary w-100">Create token</button></div>
                    </form>
                    @else
                        <div class="small text-muted mt-2">Internal model workloads run only inside Nexum and cannot issue API tokens.</div>
                    @endif
                    @foreach($workload->bindings as $binding)<div class="d-flex justify-content-between small mt-2"><span>{{ $binding->token?->name ?? 'Revoked token' }} · expires {{ $binding->expires_at->toDateString() }}</span>@if(!$binding->revoked_at && $binding->token)<form method="POST" action="{{ route('tech.admin.system.integrations.ai.privacy.bindings.revoke', $binding) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Revoke</button></form>@endif</div>@endforeach
                </div>
            @empty<p class="mb-0">No coordinator workloads exist.</p>@endforelse
        </div></div></div>
    </div>

    <!-- Metadata-only access audit -->
    <div class="card"><div class="card-header"><strong>Metadata-only access audit</strong></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Time</th><th>Route</th><th>Decision</th><th>Reason</th><th>Status</th><th>Request ID</th></tr></thead><tbody>@forelse($events as $event)<tr><td>{{ $event->created_at }}</td><td>{{ $event->route_name }}</td><td>{{ $event->decision }}</td><td><code>{{ $event->reason_code }}</code></td><td>{{ $event->http_status }}</td><td><code>{{ $event->request_id }}</code></td></tr>@empty<tr><td colspan="6" class="text-muted">No access events yet.</td></tr>@endforelse</tbody></table></div></div>
</div>
@endsection

@section('sidebar')<x-nav.admin-menu group="integrations" />@endsection
