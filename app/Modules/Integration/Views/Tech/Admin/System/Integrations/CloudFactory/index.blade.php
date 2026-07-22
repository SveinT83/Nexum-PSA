@extends('layouts.default_tech')

@section('title', 'Cloud Factory')

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.index') }}">Integrations</a></li>
            <li class="breadcrumb-item active" aria-current="page">Cloud Factory</li>
        </ol>
    </nav>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h1 class="h3 mb-0">Cloud Factory</h1>
        <a href="{{ route('tech.admin.system.integrations.cloudfactory.catalogue') }}" class="btn btn-outline-primary">
            <i class="bi bi-box-seam" aria-hidden="true"></i> Catalogue
        </a>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection
@section('rightbar')
    <!-- Cloud Factory connection guide -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0"><i class="bi bi-key" aria-hidden="true"></i> Connection guide</h2>
            <span class="badge text-bg-{{ $hasRefreshToken ? 'success' : 'secondary' }}">
                {{ $hasRefreshToken ? 'Connected' : 'Setup' }}
            </span>
        </div>
        <div class="card-body">
            <p class="small mb-2">
                Use a dedicated Cloud Factory Portal account with the required roles.
            </p>
            <ol class="small ps-3 mb-3">
                <li class="mb-2">Open the Cloud Factory refresh-token page below.</li>
                <li class="mb-2">Open the login address returned by Cloud Factory.</li>
                <li class="mb-2">Sign in with the Portal account and complete MFA.</li>
                <li class="mb-2">Copy only the <strong>Refresh Token</strong> from the token response.</li>
                <li>Paste it in Nexum and select <strong>{{ $hasRefreshToken ? 'Replace and verify token' : 'Connect and verify' }}</strong>.</li>
            </ol>

            <div class="d-grid gap-2">
                <a
                    href="https://portal.api.cloudfactory.dk/Authenticate/Login?customer=false"
                    class="btn btn-sm btn-outline-primary"
                    target="_blank"
                    rel="noopener noreferrer">
                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Get refresh token
                </a>
                <a
                    href="https://portal.api.cloudfactory.dk/swagger/index.html"
                    class="btn btn-sm btn-outline-secondary"
                    target="_blank"
                    rel="noopener noreferrer">
                    Official API guide
                </a>
                <a href="#refresh_token" class="btn btn-sm btn-outline-secondary">
                    Go to Nexum token field
                </a>
            </div>

            <div class="alert alert-warning small py-2 px-2 mt-3 mb-0" role="note">
                Use the <strong>Refresh Token</strong>, never the Access Token. Do not send either token by email or chat.
            </div>
        </div>
    </div>
@endsection


@section('content')
    <!-- Connection and capability status -->
    <div class="row g-3 mb-4">
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header"><h2 class="h5 mb-0">Dedicated Portal account</h2></div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <span class="badge text-bg-{{ $integration->status === 'active' ? 'success' : 'secondary' }}">
                            {{ ucfirst($integration->status) }}
                        </span>
                        <span class="badge text-bg-{{ $integration->is_healthy ? 'success' : 'warning' }}">
                            {{ $integration->is_healthy ? 'API verified' : 'API check failed' }}
                        </span>
                        <span class="badge text-bg-light border">
                            {{ $hasRefreshToken ? 'Refresh token stored' : 'No token' }}
                        </span>
                    </div>

                    <p class="text-muted">
                        Enter only the refresh token from a dedicated Cloud Factory Portal service account.
                        Nexum never asks for or stores its password, MFA seed, recovery code, or browser session.
                    </p>

                    @if($integration->is_healthy)
                        <p class="small text-success-emphasis mb-3">
                            <i class="bi bi-check-circle" aria-hidden="true"></i>
                            The latest connection verification or synchronization received a successful API response.
                            @if($integration->last_sync_at)
                                Last successful sync {{ $integration->last_sync_at->diffForHumans() }}.
                            @elseif(data_get($settings, 'connected_at'))
                                Connection verified {{ \Illuminate\Support\Carbon::parse(data_get($settings, 'connected_at'))->diffForHumans() }}.
                            @endif
                        </p>
                    @else
                        <p class="small text-warning-emphasis mb-3">
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            No successful API verification is currently recorded. Connecting or synchronizing will update this status.
                        </p>
                    @endif

                    <form action="{{ route('tech.admin.system.integrations.cloudfactory.connect') }}" method="POST">
                        @csrf
                        <label for="refresh_token" class="form-label">Refresh token</label>
                        <input
                            id="refresh_token"
                            name="refresh_token"
                            type="password"
                            class="form-control @error('refresh_token') is-invalid @enderror"
                            autocomplete="new-password"
                            value=""
                            placeholder="{{ $hasRefreshToken ? 'Enter a replacement token' : 'Paste the one-time token' }}"
                            required>
                        @error('refresh_token')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">The stored value is encrypted and is never displayed again.</div>
                        <button type="submit" class="btn btn-primary mt-3">
                            {{ $hasRefreshToken ? 'Replace and verify token' : 'Connect and verify' }}
                        </button>
                    </form>

                    @if($hasRefreshToken)
                        <hr>
                        <form action="{{ route('tech.admin.system.integrations.cloudfactory.revoke') }}" method="POST"
                              onsubmit="return confirm('Revoke every token for this dedicated Cloud Factory account and disconnect?')">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger">Revoke and disconnect</button>
                        </form>
                        @error('revoke')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h2 class="h5 mb-0">Provider capabilities</h2>
                    @if($integration->status === 'active' && $hasRefreshToken)
                        <form action="{{ route('tech.admin.system.integrations.cloudfactory.capabilities.refresh') }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Refresh capabilities
                            </button>
                        </form>
                    @endif
                </div>
                <div class="card-body">
                    @php
                        $rolesChecked = filled(data_get($settings, 'roles_checked_at'));
                        $capabilityLabels = [
                            'customers' => 'Customers / catalogue',
                            'microsoft' => 'Microsoft / MCA',
                            'adobe' => 'Adobe',
                            'finance' => 'Invoices',
                            'notifications' => 'Notifications',
                            'activity_log' => 'Activity log',
                        ];
                    @endphp
                    <div class="row g-2 mb-3">
                        @foreach($capabilityLabels as $key => $label)
                            @php
                                $available = data_get($settings, 'capabilities.'.$key, false);
                                $badgeStyle = ! $rolesChecked ? 'warning' : ($available ? 'success' : 'secondary');
                                $badgeLabel = ! $rolesChecked ? 'Not checked' : ($available ? 'Available' : 'Missing role');
                            @endphp
                            <div class="col-sm-6">
                                <div class="border rounded p-2 d-flex justify-content-between align-items-center">
                                    <span>{{ $label }}</span>
                                    <span class="badge text-bg-{{ $badgeStyle }}">
                                        {{ $badgeLabel }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="small text-muted">
                        Discovered roles:
                        @if($rolesChecked)
                            {{ collect($settings['roles'] ?? [])->join(', ') ?: 'No roles returned by Cloud Factory.' }}
                        @else
                            Capabilities have not been checked yet.
                        @endif
                    </div>
                    @if($rolesChecked)
                        <div class="small text-muted mt-1">Last checked {{ data_get($settings, 'roles_checked_at') }}.</div>
                    @endif
                    @if(filled(data_get($settings, 'roles_last_error')))
                        <div class="alert alert-warning py-2 mt-3 mb-0">
                            The last capability refresh failed: {{ data_get($settings, 'roles_last_error') }}
                        </div>
                    @endif
                    @error('capabilities')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    <div class="border rounded p-3 mt-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <strong>Notification webhooks</strong>
                            <span class="badge text-bg-{{ data_get($settings, 'webhooks_enabled', false) ? 'success' : 'secondary' }}">
                                {{ data_get($settings, 'webhooks_enabled', false) ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                        <p class="small text-muted mt-2 mb-2">
                            Cloud Factory sends the shared key in <code>X-API-KEY</code>. Nexum validates it,
                            removes duplicate deliveries, and queues an authoritative reconciliation. Scheduled
                            polling remains enabled as a safety net.
                        </p>
                        @if(data_get($settings, 'webhooks_enabled', false))
                            <div class="small text-muted mb-2">
                                {{ count($settings['webhook_events'] ?? []) }} events registered.
                                Last registered {{ data_get($settings, 'webhooks_registered_at', 'unknown') }}.
                            </div>
                        @endif
                        @if($integration->status === 'active' && data_get($settings, 'capabilities.notifications', false))
                            @if(data_get($settings, 'webhooks_enabled', false))
                                <form action="{{ route('tech.admin.system.integrations.cloudfactory.webhooks.disable') }}" method="POST"
                                      onsubmit="return confirm('Remove Cloud Factory webhook registrations and delete the shared key?')">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Disable webhooks</button>
                                </form>
                            @else
                                <form action="{{ route('tech.admin.system.integrations.cloudfactory.webhooks.enable') }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Enable and register webhooks</button>
                                </form>
                            @endif
                        @else
                            <div class="small text-muted">
                                {{ $rolesChecked
                                    ? 'Connect an account with Partner Admin to register notifications.'
                                    : 'Refresh capabilities to check whether the account can register notifications.' }}
                            </div>
                        @endif
                        @error('webhook')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Automatic synchronization and pricing settings -->
    <form action="{{ route('tech.admin.system.integrations.cloudfactory.update') }}" method="POST" class="card mb-4">
        @csrf
        @method('PUT')
        <div class="card-header p-0">
            <h2 class="h5 mb-0">
                <button
                    type="button"
                    class="btn btn-link text-body text-decoration-none w-100 d-flex justify-content-between align-items-center gap-3 px-3 py-2 {{ $errors->any() ? '' : 'collapsed' }}"
                    data-bs-toggle="collapse"
                    data-bs-target="#cloudfactory-automation-settings"
                    aria-expanded="{{ $errors->any() ? 'true' : 'false' }}"
                    aria-controls="cloudfactory-automation-settings">
                    <span>Automation, pricing, and write safety</span>
                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                </button>
            </h2>
        </div>
        <div id="cloudfactory-automation-settings" class="collapse {{ $errors->any() ? 'show' : '' }}">
            <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="customer_sync_minutes" class="form-label">Client sync (minutes)</label>
                    <input id="customer_sync_minutes" name="customer_sync_minutes" type="number" min="5" max="1440"
                           class="form-control" value="{{ old('customer_sync_minutes', $settings['customer_sync_minutes']) }}" required>
                </div>
                <div class="col-md-3">
                    <label for="subscription_sync_minutes" class="form-label">Licence sync (minutes)</label>
                    <input id="subscription_sync_minutes" name="subscription_sync_minutes" type="number" min="5" max="1440"
                           class="form-control" value="{{ old('subscription_sync_minutes', $settings['subscription_sync_minutes']) }}" required>
                </div>
                <div class="col-md-3">
                    <label for="catalogue_sync_day" class="form-label">Monthly price day</label>
                    <input id="catalogue_sync_day" name="catalogue_sync_day" type="number" min="1" max="28"
                           class="form-control" value="{{ old('catalogue_sync_day', $settings['catalogue_sync_day']) }}" required>
                </div>
                <div class="col-md-3">
                    <label for="catalogue_sync_time" class="form-label">Monthly price time</label>
                    <input id="catalogue_sync_time" name="catalogue_sync_time" type="time"
                           class="form-control" value="{{ old('catalogue_sync_time', $settings['catalogue_sync_time']) }}" required>
                </div>

                <div class="col-md-4">
                    <label for="pricing_mode" class="form-label">Default sales price</label>
                    <select id="pricing_mode" name="pricing_mode" class="form-select" required>
                        <option value="follow_msrp" @selected(old('pricing_mode', $settings['pricing_mode']) === 'follow_msrp')>Follow MSRP</option>
                        <option value="msrp_markup" @selected(old('pricing_mode', $settings['pricing_mode']) === 'msrp_markup')>MSRP plus percentage</option>
                        <option value="cost_markup" @selected(old('pricing_mode', $settings['pricing_mode']) === 'cost_markup')>Cost plus percentage</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="markup_percent" class="form-label">Markup %</label>
                    <input id="markup_percent" name="markup_percent" type="number" step="0.01" min="-100" max="1000"
                           class="form-control" value="{{ old('markup_percent', $settings['markup_percent']) }}" required>
                </div>
                <div class="col-md-2">
                    <label for="default_currency" class="form-label">Currency</label>
                    <input id="default_currency" name="default_currency" maxlength="3" class="form-control"
                           value="{{ old('default_currency', $settings['default_currency']) }}" required>
                </div>
                <div class="col-md-2">
                    <label for="default_country_code" class="form-label">Country</label>
                    <input id="default_country_code" name="default_country_code" maxlength="2" class="form-control"
                           value="{{ old('default_country_code', $settings['default_country_code']) }}" required>
                </div>
                <div class="col-md-2">
                    <label for="default_unit_id" class="form-label">Service unit</label>
                    <select id="default_unit_id" name="default_unit_id" class="form-select" required>
                        <option value="">Select</option>
                        @foreach($units as $unit)
                            <option value="{{ $unit->id }}" @selected((int) old('default_unit_id', $settings['default_unit_id']) === $unit->id)>
                                {{ $unit->name }} ({{ $unit->short }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-5">
                    <label for="test_client_id" class="form-label">Allowlisted fictitious Client</label>
                    <select id="test_client_id" name="test_client_id" class="form-select">
                        <option value="">Select before enabling writes</option>
                        @foreach($clients as $client)
                            <option value="{{ $client->id }}" @selected((int) old('test_client_id', $settings['test_client_id']) === $client->id)>
                                {{ $client->client_number }} - {{ $client->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('test_client_id')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label for="write_scope" class="form-label">Write scope</label>
                    <select id="write_scope" name="write_scope" class="form-select">
                        <option value="test_client" @selected(old('write_scope', $settings['write_scope'] ?? 'test_client') === 'test_client')>Fictitious Client only</option>
                        <option value="all"
                                @disabled(blank($settings['validation_completed_at'] ?? null))
                                @selected(old('write_scope', $settings['write_scope'] ?? 'test_client') === 'all')>All eligible Clients</option>
                    </select>
                    @error('write_scope')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label for="microsoft_billing_cycle_type" class="form-label">Microsoft billing type</label>
                    <input id="microsoft_billing_cycle_type" name="microsoft_billing_cycle_type" type="number" min="0" max="10"
                           class="form-control" value="{{ old('microsoft_billing_cycle_type', $settings['microsoft_billing_cycle_type'] ?? 1) }}" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div>
                        <input type="hidden" name="writes_enabled" value="0">
                        <div class="form-check form-switch">
                            <input id="writes_enabled" name="writes_enabled" value="1" type="checkbox" class="form-check-input"
                                   @checked(old('writes_enabled', $settings['writes_enabled']))>
                            <label for="writes_enabled" class="form-check-label">Provider writes</label>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="d-flex flex-wrap gap-4">
                        @foreach([
                            'sync_enabled' => ['Automatic sync', true],
                            'create_missing_clients' => ['Create missing Nexum Clients', true],
                            'push_client_updates' => ['Push Nexum Client changes', true],
                        ] as $field => [$label, $default])
                            <div class="form-check form-switch">
                                <input type="hidden" name="{{ $field }}" value="0">
                                <input id="{{ $field }}" name="{{ $field }}" value="1" type="checkbox" class="form-check-input"
                                       @checked(old($field, $settings[$field] ?? $default))>
                                <label for="{{ $field }}" class="form-check-label">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="alert alert-warning mt-3">
                Cloud Factory has no sandbox. Until a confirmed operation exists for the allowlisted fictitious Client,
                every write to another Client is rejected on the server.
            </div>
            <button type="submit" class="btn btn-primary">Save settings</button>
        </div>
        </div>
    </form>

    <!-- Manual synchronization and validation -->
    <div class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0">Run synchronization</h2></div>
        <div class="card-body">
            <p class="small text-muted">
                Manual synchronization runs on the queue. The live window can be closed without stopping the job.
            </p>
            <div class="d-flex flex-wrap gap-2">
                @foreach(['all' => 'Everything', 'customers' => 'Clients', 'catalogue' => 'Catalogue and prices', 'subscriptions' => 'Licences'] as $kind => $label)
                    <form action="{{ route('tech.admin.system.integrations.cloudfactory.sync') }}"
                          method="POST"
                          class="cloudfactory-sync-form">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $kind }}">
                        <button
                            type="submit"
                            class="btn btn-outline-primary"
                            @disabled($integration->status !== 'active' || $activeSyncRun)>
                            {{ $label }}
                        </button>
                    </form>
                @endforeach

                @if($activeSyncRun)
                    <button
                        type="button"
                        id="cloudfactory-sync-resume"
                        class="btn btn-outline-info"
                        data-status-url="{{ route('tech.admin.system.integrations.cloudfactory.sync.status', $activeSyncRun) }}">
                        <i class="bi bi-activity" aria-hidden="true"></i> View current sync
                    </button>
                @endif

                @if(blank($settings['validation_completed_at'] ?? null))
                    <form action="{{ route('tech.admin.system.integrations.cloudfactory.validation') }}" method="POST" class="ms-auto">
                        @csrf
                        <button type="submit" class="btn btn-outline-success">Confirm completed fictitious-Client test</button>
                    </form>
                @else
                    <span class="badge text-bg-success ms-auto align-self-center">
                        Validation completed {{ \Illuminate\Support\Carbon::parse($settings['validation_completed_at'])->format('d.m.Y H:i') }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    <!-- Live progress for queued manual synchronization -->
    <div class="modal fade" id="cloudfactory-sync-modal" tabindex="-1"
         aria-labelledby="cloudfactory-sync-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5 mb-1" id="cloudfactory-sync-modal-title">Cloud Factory synchronization</h2>
                        <div class="small text-muted">Real progress reported by the background queue worker.</div>
                    </div>
                    <span id="cloudfactory-sync-run-status" class="badge text-bg-secondary ms-auto me-3" aria-live="polite">
                        Preparing
                    </span>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2" role="note">
                        You may close this window. Synchronization continues in the background.
                    </div>
                    <div id="cloudfactory-sync-error" class="alert alert-danger d-none" role="alert"></div>

                    <div id="cloudfactory-sync-worker-warning" class="alert alert-warning d-none" role="alert">
                        The background worker has not picked up this job yet.
                        <a href="{{ route('tech.admin.system.queues-workers.index') }}" class="alert-link">
                            Check Queues and Workers
                        </a>.
                    </div>
                    @foreach([
                        'customers' => ['label' => 'Clients', 'icon' => 'bi-building'],
                        'catalogue' => ['label' => 'Catalogue and prices', 'icon' => 'bi-box-seam'],
                        'subscriptions' => ['label' => 'Licences', 'icon' => 'bi-key'],
                    ] as $category => $definition)
                        <section class="border rounded p-3 mb-3 d-none" data-sync-category="{{ $category }}">
                            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                <h3 class="h6 mb-0">
                                    <i class="bi {{ $definition['icon'] }}" aria-hidden="true"></i>
                                    {{ $definition['label'] }}
                                </h3>
                                <span class="badge text-bg-secondary" data-sync-status>Queued</span>
                            </div>
                            <div class="d-flex flex-wrap justify-content-between gap-2 small mb-1">
                                <strong data-sync-counter aria-live="polite">0 items processed</strong>
                                <span class="text-muted" data-sync-sources></span>
                            </div>
                            <div class="progress" role="progressbar" aria-label="{{ $definition['label'] }} progress"
                                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                                <div class="progress-bar" data-sync-bar style="width: 0%"></div>
                            </div>
                            <div class="d-flex flex-wrap justify-content-between gap-2 small mt-2">
                                <span class="text-muted" data-sync-message>Waiting for the queue worker.</span>
                                <span class="text-muted" data-sync-results>0 new &middot; 0 updated &middot; 0 conflicts</span>
                            </div>
                        </section>
                    @endforeach
                </div>
                <div class="modal-footer">
                    <span class="small text-muted me-auto">Counts may skip numbers between screen updates, but are never simulated.</span>
                    <button type="button" id="cloudfactory-sync-reload" class="btn btn-primary d-none">Reload page</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Collapsible conflicts and recent integration activity -->
    <div class="card mb-4">
        <div class="card-header p-0">
            <h2 class="h5 mb-0">
                <button
                    type="button"
                    class="btn btn-link text-body text-decoration-none w-100 d-flex justify-content-between align-items-center gap-3 px-3 py-2 collapsed"
                    data-bs-toggle="collapse"
                    data-bs-target="#cloudfactory-operational-history"
                    aria-expanded="false"
                    aria-controls="cloudfactory-operational-history">
                    <span>Conflicts and recent activity</span>
                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                </button>
            </h2>
        </div>
        <div id="cloudfactory-operational-history" class="collapse">
            <div class="card-body">
    <!-- Conflicts requiring an explicit human link -->
    <div class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0">Open conflicts</h2></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Type</th><th>External record</th><th>Client</th><th>Created</th><th>Manual link</th></tr></thead>
                <tbody>
                    @forelse($conflicts as $conflict)
                        <tr>
                            <td>{{ str_replace('_', ' ', ucfirst($conflict->conflict_type)) }}</td>
                            <td>{{ $conflict->external_id ?: '-' }}</td>
                            <td>{{ $conflict->client?->name ?: 'Unlinked' }}</td>
                            <td>{{ $conflict->created_at->format('d.m.Y H:i') }}</td>
                            <td>
                                @if($conflict->conflict_type === 'client_match')
                                    <form action="{{ route('tech.admin.system.integrations.cloudfactory.conflicts.link-client', $conflict) }}" method="POST" class="d-flex gap-2">
                                        @csrf
                                        <select name="client_id" class="form-select form-select-sm" required>
                                            <option value="">Choose Client</option>
                                            @foreach($clients as $client)
                                                <option value="{{ $client->id }}">{{ $client->name }}</option>
                                            @endforeach
                                        </select>
                                        <button class="btn btn-sm btn-primary">Link</button>
                                    </form>
                                @else
                                    <span class="text-muted small">Review contract, Service, role, or provider state.</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No open conflicts.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent runtime history -->
    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h2 class="h5 mb-0">Latest sync runs</h2></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Kind</th><th>Status</th><th>Seen</th><th>Conflicts</th><th>Started</th></tr></thead>
                        <tbody>
                            @forelse($latestRuns as $run)
                                <tr>
                                    <td>{{ ucfirst($run->kind) }}</td>
                                    <td><span class="badge text-bg-{{ $run->status === 'completed' ? 'success' : ($run->status === 'failed' ? 'danger' : 'warning') }}">{{ $run->status }}</span></td>
                                    <td>{{ $run->records_seen }}</td>
                                    <td>{{ $run->records_conflicted }}</td>
                                    <td>{{ $run->started_at->format('d.m H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">No runs yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h2 class="h5 mb-0">Latest provider operations</h2></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Client</th><th>Action</th><th>Provider</th><th>Status</th><th>Created</th></tr></thead>
                        <tbody>
                            @forelse($operations as $operation)
                                <tr>
                                    <td>{{ $operation->client?->name ?: '-' }}</td>
                                    <td>{{ ucfirst($operation->action) }}</td>
                                    <td>{{ ucfirst($operation->provider_family ?: 'Cloud Factory') }}</td>
                                    <td><span class="badge text-bg-{{ $operation->status === 'confirmed' ? 'success' : ($operation->status === 'failed' ? 'danger' : 'warning') }}">{{ $operation->status }}</span></td>
                                    <td>{{ $operation->created_at->format('d.m H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">No write operations yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Cloud Factory notification deliveries -->
    <div class="card mt-4">
        <div class="card-header"><h2 class="h5 mb-0">Latest notification webhooks</h2></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Event</th><th>State</th><th>Occurred</th><th>First sent</th><th>Received</th></tr></thead>
                <tbody>
                    @forelse($latestWebhookReceipts as $receipt)
                        <tr>
                            <td>{{ $receipt->event_key }}</td>
                            <td>
                                <span class="badge text-bg-{{ $receipt->processing_state === 'processed' ? 'success' : ($receipt->processing_state === 'failed' ? 'danger' : 'warning') }}">
                                    {{ $receipt->processing_state }}
                                </span>
                            </td>
                            <td>{{ $receipt->provider_created_at->format('d.m H:i') }}</td>
                            <td>{{ $receipt->provider_sent_at->format('d.m H:i') }}</td>
                            <td>{{ $receipt->received_at->format('d.m H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No webhook deliveries yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modalElement = document.getElementById('cloudfactory-sync-modal');

            if (!modalElement || !window.bootstrap) {
                return;
            }

            const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            const forms = Array.from(document.querySelectorAll('.cloudfactory-sync-form'));
            const resumeButton = document.getElementById('cloudfactory-sync-resume');
            const reloadButton = document.getElementById('cloudfactory-sync-reload');
            const runStatus = document.getElementById('cloudfactory-sync-run-status');
            const errorBox = document.getElementById('cloudfactory-sync-error');
            const workerWarning = document.getElementById('cloudfactory-sync-worker-warning');
            const integrationActive = @json($integration->status === 'active');
            const categoryKeys = ['customers', 'catalogue', 'subscriptions'];
            const terminalStatuses = ['completed', 'failed'];
            let statusUrl = null;
            let pollTimer = null;

            const statusLabels = {
                queued: 'Queued',
                running: 'Running',
                retrying: 'Retrying',
                completed: 'Completed',
                failed: 'Failed',
                skipped: 'Skipped',
            };

            const statusClasses = {
                queued: 'text-bg-secondary',
                running: 'text-bg-primary',
                retrying: 'text-bg-warning',
                completed: 'text-bg-success',
                failed: 'text-bg-danger',
                skipped: 'text-bg-secondary',
            };

            const formatNumber = value => Number(value || 0).toLocaleString();

            function setManualButtonsDisabled(disabled) {
                forms.forEach(form => {
                    const button = form.querySelector('button[type="submit"]');

                    if (button) {
                        button.disabled = disabled || !integrationActive;
                    }
                });
            }

            function showError(message) {
                errorBox.textContent = message || '';
                errorBox.classList.toggle('d-none', !message);
            }

            function renderCategory(key, entry) {
                const section = modalElement.querySelector('[data-sync-category="' + key + '"]');

                if (!section) {
                    return;
                }

                section.classList.toggle('d-none', !entry);

                if (!entry) {
                    return;
                }

                const status = entry.status || 'queued';
                const statusBadge = section.querySelector('[data-sync-status]');
                const counter = section.querySelector('[data-sync-counter]');
                const sources = section.querySelector('[data-sync-sources]');
                const message = section.querySelector('[data-sync-message]');
                const results = section.querySelector('[data-sync-results]');
                const bar = section.querySelector('[data-sync-bar]');
                const progress = bar.closest('.progress');
                const processed = Number(entry.processed || 0);
                const hasTotal = entry.total !== null && entry.total !== undefined;
                const total = hasTotal ? Number(entry.total) : null;
                const sourceProcessed = Number(entry.sources_processed || 0);
                const hasSourceTotal = entry.sources_total !== null && entry.sources_total !== undefined;
                const sourceTotal = hasSourceTotal ? Number(entry.sources_total) : null;
                const unit = entry.unit || 'items';
                let percent = 0;
                let indeterminate = false;

                statusBadge.textContent = statusLabels[status] || status;
                statusBadge.className = 'badge ' + (statusClasses[status] || 'text-bg-secondary');

                counter.textContent = hasTotal
                    ? formatNumber(processed) + ' of ' + formatNumber(total) + ' ' + unit
                    : formatNumber(processed) + ' ' + unit + ' processed';

                sources.textContent = hasSourceTotal
                    ? formatNumber(sourceProcessed) + ' of ' + formatNumber(sourceTotal) + ' Client/provider checks'
                    : '';

                if (status === 'completed') {
                    percent = 100;
                } else if (hasTotal && total > 0) {
                    percent = Math.min(100, Math.round((processed / total) * 100));
                } else if (hasSourceTotal && sourceTotal > 0) {
                    percent = Math.min(100, Math.round((sourceProcessed / sourceTotal) * 100));
                } else if (['running', 'retrying'].includes(status)) {
                    percent = 35;
                    indeterminate = true;
                }

                bar.style.width = percent + '%';
                bar.className = 'progress-bar';

                if (indeterminate) {
                    bar.classList.add('progress-bar-striped', 'progress-bar-animated');
                }

                if (status === 'completed') {
                    bar.classList.add('bg-success');
                } else if (status === 'failed') {
                    bar.classList.add('bg-danger');
                } else if (status === 'retrying') {
                    bar.classList.add('bg-warning');
                }

                progress.setAttribute('aria-valuenow', String(percent));
                message.textContent = entry.message || '';
                results.textContent = formatNumber(entry.created) + ' new \u00b7 '
                    + formatNumber(entry.updated) + ' updated \u00b7 '
                    + formatNumber(entry.conflicted) + ' conflicts';
            }

            function renderRun(run) {
                const status = run.status || 'queued';
                const progress = run.progress || {};

                runStatus.textContent = statusLabels[status] || status;
                runStatus.className = 'badge ms-auto me-3 ' + (statusClasses[status] || 'text-bg-secondary');
                workerWarning.classList.toggle(
                    'd-none',
                    status !== 'queued' || Number(run.queued_for_seconds || 0) < 10
                );
                categoryKeys.forEach(key => renderCategory(key, progress[key] || null));
                showError(run.error || '');

                const terminal = terminalStatuses.includes(status);
                reloadButton.classList.toggle('d-none', !terminal);
                setManualButtonsDisabled(!terminal);

                if (resumeButton && terminal) {
                    resumeButton.classList.add('d-none');
                }

                return terminal;
            }

            function syntheticRun(kind) {
                const selected = kind === 'all' ? categoryKeys : [kind];
                const progress = {};

                selected.forEach(key => {
                    progress[key] = {
                        status: 'queued',
                        message: 'Creating queue job.',
                        processed: 0,
                        total: null,
                        sources_processed: 0,
                        sources_total: null,
                        created: 0,
                        updated: 0,
                        conflicted: 0,
                        unit: key === 'customers' ? 'clients' : (key === 'catalogue' ? 'products' : 'licences'),
                    };
                });

                return {status: 'queued', progress};
            }

            function schedulePoll(delay = 1000) {
                window.clearTimeout(pollTimer);
                pollTimer = window.setTimeout(pollRun, delay);
            }

            async function pollRun() {
                if (!statusUrl) {
                    return;
                }

                try {
                    const response = await fetch(statusUrl, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });
                    const data = await response.json();

                    if (!response.ok || !data.run) {
                        throw new Error(data.message || 'Could not read synchronization progress.');
                    }

                    if (!renderRun(data.run)) {
                        schedulePoll();
                    }
                } catch (error) {
                    showError((error.message || 'Progress connection was interrupted.') + ' Retrying&');
                    schedulePoll(3000);
                }
            }

            function attachRun(run) {
                statusUrl = run.status_url || statusUrl;
                const terminal = renderRun(run);
                modal.show();

                if (!terminal && statusUrl) {
                    schedulePoll(250);
                }
            }

            forms.forEach(form => {
                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    window.clearTimeout(pollTimer);
                    statusUrl = null;
                    showError('');
                    reloadButton.classList.add('d-none');
                    setManualButtonsDisabled(true);

                    const formData = new FormData(form);
                    attachRun(syntheticRun(formData.get('kind')));

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                        });
                        const data = await response.json();

                        if ((response.ok || response.status === 409) && data.run) {
                            attachRun(data.run);

                            return;
                        }

                        const validationMessage = Object.values(data.errors || {})[0]?.[0];
                        throw new Error(validationMessage || data.message || 'Could not queue synchronization.');
                    } catch (error) {
                        showError(error.message || 'Could not queue synchronization.');
                        runStatus.textContent = 'Not started';
                        runStatus.className = 'badge text-bg-danger ms-auto me-3';
                        setManualButtonsDisabled(false);
                    }
                });
            });

            resumeButton?.addEventListener('click', () => {
                statusUrl = resumeButton.dataset.statusUrl;
                showError('');
                reloadButton.classList.add('d-none');
                setManualButtonsDisabled(true);
                modal.show();
                pollRun();
            });

            reloadButton.addEventListener('click', () => window.location.reload());
        });
    </script>
@endsection
