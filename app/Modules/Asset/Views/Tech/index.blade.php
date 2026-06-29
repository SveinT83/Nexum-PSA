@extends('layouts.default_tech')

@section('title', 'Assets')

@php
    $rmmIntegration = \App\Models\System\Integrations\Integration::where('type', 'rmm')->where('status', 'active')->first();
    $tacticalIntegration = \App\Models\System\Integrations\Integration::where('type', 'tactical_rmm')->where('status', 'active')->first();
    $canSyncNable = $client && $rmmIntegration && $client->rmmLinks()->where('integration_id', $rmmIntegration->id)->exists();
    $canSyncTactical = $client && $tacticalIntegration && $client->rmmLinks()->where('integration_id', $tacticalIntegration->id)->exists();
    $sort = request('sort', 'last_seen_at');
    $direction = request('direction') === 'asc' ? 'asc' : 'desc';
    $sortLink = function (string $column) use ($sort, $direction) {
        $nextDirection = $sort === $column && $direction === 'asc' ? 'desc' : 'asc';

        return request()->fullUrlWithQuery([
            'sort' => $column,
            'direction' => $nextDirection,
        ]);
    };
    $sortIcon = function (string $column) use ($sort, $direction) {
        if ($sort !== $column) {
            return 'bi-arrow-down-up';
        }

        return $direction === 'asc' ? 'bi-sort-alpha-down' : 'bi-sort-alpha-up';
    };
@endphp

@section('pageHeader')
    <!-- ======================================================================
         PAGE HEADER
         - Dynamic Title based on Client context
         - Page title with only navigation-level action
         ====================================================================== -->
    <div class="d-flex justify-content-between align-items-center">
        <h1>
            @if($client)
                Assets for {{ $client->name }}
            @else
                Assets
            @endif
        </h1>
        <div>
            @if($client)
                <x-buttons.back :url="route('tech.clients.show', $client->id)" class="mb-0">Back</x-buttons.back>
            @endif
        </div>
    </div>
@endsection

@section('content')
    <!-- ======================================================================
         FILTERS SECTION
         - Allows filtering by Client (if global view), Type, and Status
         ====================================================================== -->
    <div class="card mb-3">
        <div class="card-body">
            <form action="{{ $client ? route('tech.clients.assets.index', $client->id) : route('tech.assets.index') }}" method="GET" class="row g-3">
                @if(!$client)
                    <div class="col-md-4">
                        <label for="client_id" class="form-label text-muted small fw-bold text-uppercase">Client</label>
                        <select name="client_id" id="client_id" class="form-select">
                            <option value="">All Clients</option>
                            @foreach($clients as $c)
                                <option value="{{ $c->id }}" {{ request('client_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-md-3">
                    <label for="type" class="form-label text-muted small fw-bold text-uppercase">Type</label>
                    <select name="type" id="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="server" {{ request('type') == 'server' ? 'selected' : '' }}>Server</option>
                        <option value="pc" {{ request('type') == 'pc' ? 'selected' : '' }}>PC</option>
                        <option value="laptop" {{ request('type') == 'laptop' ? 'selected' : '' }}>Laptop</option>
                        <option value="switch" {{ request('type') == 'switch' ? 'selected' : '' }}>Switch</option>
                        <option value="ap" {{ request('type') == 'ap' ? 'selected' : '' }}>Access Point</option>
                        <option value="firewall" {{ request('type') == 'firewall' ? 'selected' : '' }}>Firewall</option>
                        <option value="other" {{ request('type') == 'other' ? 'selected' : '' }}>Other</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="status" class="form-label text-muted small fw-bold text-uppercase">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="online" {{ request('status') == 'online' ? 'selected' : '' }}>Online</option>
                        <option value="offline" {{ request('status') == 'offline' ? 'selected' : '' }}>Offline</option>
                        <option value="unknown" {{ request('status') == 'unknown' ? 'selected' : '' }}>Unknown</option>
                        <option value="in_service" {{ request('status') == 'in_service' ? 'selected' : '' }}>In Service</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="sensitivity_level" class="form-label text-muted small fw-bold text-uppercase">Sensitivity</label>
                    <select name="sensitivity_level" id="sensitivity_level" class="form-select">
                        <option value="">All Sensitivity</option>
                        <option value="low" {{ request('sensitivity_level') == 'low' ? 'selected' : '' }}>Low</option>
                        <option value="medium" {{ request('sensitivity_level') == 'medium' ? 'selected' : '' }}>Medium</option>
                        <option value="high" {{ request('sensitivity_level') == 'high' ? 'selected' : '' }}>High</option>
                        <option value="ultra" {{ request('sensitivity_level') == 'ultra' ? 'selected' : '' }}>Ultra</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="criticality_level" class="form-label text-muted small fw-bold text-uppercase">Criticality</label>
                    <select name="criticality_level" id="criticality_level" class="form-select">
                        <option value="">All Criticality</option>
                        <option value="low" {{ request('criticality_level') == 'low' ? 'selected' : '' }}>Low</option>
                        <option value="medium" {{ request('criticality_level') == 'medium' ? 'selected' : '' }}>Medium</option>
                        <option value="high" {{ request('criticality_level') == 'high' ? 'selected' : '' }}>High</option>
                        <option value="critical" {{ request('criticality_level') == 'critical' ? 'selected' : '' }}>Critical</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="has_alerts" value="1" id="has_alerts" {{ request('has_alerts') ? 'checked' : '' }}>
                        <label class="form-check-label text-muted small fw-bold text-uppercase" for="has_alerts">
                            With Active Alerts
                        </label>
                    </div>
                </div>
                <input type="hidden" name="sort" value="{{ $sort }}">
                <input type="hidden" name="direction" value="{{ $direction }}">
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <a href="{{ route('tech.assets.create') }}" class="btn btn-primary w-100 mb-0">
                        <i class="bi bi-plus-lg"></i> New Asset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================================
         ASSETS DATA TABLE
         - Lists all assets with key identification and status info
         - Includes pagination
         ====================================================================== -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">
                                <a href="{{ $sortLink('name') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                    Name / Hostname <i class="bi {{ $sortIcon('name') }}"></i>
                                </a>
                            </th>
                            <th>
                                <a href="{{ $sortLink('type') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                    Type <i class="bi {{ $sortIcon('type') }}"></i>
                                </a>
                            </th>
                            <th>
                                <a href="{{ $sortLink('client_site') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                    Client / Site <i class="bi {{ $sortIcon('client_site') }}"></i>
                                </a>
                            </th>
                            <th>
                                <a href="{{ $sortLink('status') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                    Status <i class="bi {{ $sortIcon('status') }}"></i>
                                </a>
                            </th>
                            <th>Classification</th>
                            <th>
                                <a href="{{ $sortLink('last_seen_at') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                    Last Seen <i class="bi {{ $sortIcon('last_seen_at') }}"></i>
                                </a>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($assets as $asset)
                            <tr class="cursor-pointer" data-href="{{ route('tech.assets.show', ['asset' => $asset->id, 'tab' => 'summary']) }}" onclick="window.location.href = this.dataset.href">
                                <td class="ps-3">
                                    <a href="{{ route('tech.assets.show', ['asset' => $asset->id, 'tab' => 'summary']) }}" class="fw-bold text-decoration-none" onclick="event.stopPropagation()">
                                        {{ $asset->name }}
                                    </a>
                                    @if($asset->hostname)
                                        <div class="small text-muted">{{ $asset->hostname }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        {{ ucfirst($asset->type) }}
                                    </span>
                                </td>
                                <td>
                                    <div>{{ $asset->client->name }}</div>
                                    @if($asset->site)
                                        <div class="small text-muted">{{ $asset->site->name }}</div>
                                    @else
                                        <div class="small text-muted">—</div>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $activeAlertsCount = $asset->alerts()->where('status', 'active')->count();
                                    @endphp
                                    @if($activeAlertsCount > 0)
                                        <a href="{{ route('tech.assets.show', $asset->id) }}#alerts" class="badge bg-danger text-decoration-none" onclick="event.stopPropagation()">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i> {{ $activeAlertsCount }} Alerts
                                        </a>
                                    @else
                                        <span class="badge bg-light text-dark border">
                                            Online
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if($asset->sensitivity_level)
                                        <span class="badge bg-warning text-dark border me-1">
                                            S: {{ ucfirst($asset->sensitivity_level) }}
                                        </span>
                                    @endif

                                    @if($asset->criticality_level)
                                        <span class="badge {{ $asset->criticality_level === 'critical' ? 'bg-danger' : 'bg-info text-dark' }} border">
                                            C: {{ ucfirst($asset->criticality_level) }}
                                        </span>
                                    @endif

                                    @if(!$asset->sensitivity_level && !$asset->criticality_level)
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                                <td>
                                    <small class="{{ $asset->last_seen_at ? '' : 'text-muted' }}">{{ $asset->last_seen_at ? $asset->last_seen_at->diffForHumans() : '—' }}</small>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-cpu-fill display-4 d-block mb-3 opacity-25"></i>
                                    No assets found matching your criteria.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PAGINATION -->
        @if($assets->hasPages())
            <div class="card-footer bg-white border-top-0 py-3">
                {{ $assets->links() }}
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.work-menu />
@endsection

@section('rightbar')

    <!-- Alerts Summary Widget -->
    @livewire('tech.work.assets.client-alerts-summary', ['client' => $client])

    @if($client)
        <!-- ------------------------------------------------- -->
        <!-- Integration actions -->
        <!-- ------------------------------------------------- -->
        <div class="accordion mb-3" id="assetIndexActionsAccordion">
            <div class="accordion-item">
                <h2 class="accordion-header" id="assetIndexActionsHeader">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#assetIndexActionsCollapse" aria-expanded="false" aria-controls="assetIndexActionsCollapse">
                        Integrations
                    </button>
                </h2>
                <div id="assetIndexActionsCollapse" class="accordion-collapse collapse" aria-labelledby="assetIndexActionsHeader" data-bs-parent="#assetIndexActionsAccordion">
                    <div class="accordion-body d-grid gap-2">
                        @if($rmmIntegration)
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    @if(!$canSyncNable) disabled title="Client not linked to N-able RMM" @endif
                                    onclick="Livewire.dispatch('startTargetedSync', { params: { type: 'assets_from', client_id: {{ $client->id }} } })">
                                <i class="bi bi-arrow-repeat me-1"></i> Sync Assets (N-able)
                            </button>
                        @endif
                        @if($tacticalIntegration)
                            <button type="button"
                                    class="btn btn-sm btn-outline-info"
                                    @if(!$canSyncTactical) disabled title="Client not linked to Tactical RMM" @endif
                                    onclick="Livewire.dispatch('startTargetedTacticalSync', { params: { type: 'assets_from', client_id: {{ $client->id }} } })">
                                <i class="bi bi-arrow-repeat me-1"></i> Sync Assets (Tactical)
                            </button>
                        @endif
                        <button type="button" class="btn btn-sm btn-outline-warning" onclick="Livewire.dispatchTo('tech.work.assets.client-alerts-summary', 'syncAlerts')">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Sync Alerts
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- ======================================================================
         DOCUMENTATION CARD
         - Provides a summary of the module and a link to the full documentation
         ====================================================================== -->
    <div class="accordion mb-3" id="assetIndexDocumentationAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="assetIndexDocumentationHeader">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#assetIndexDocumentationCollapse" aria-expanded="false" aria-controls="assetIndexDocumentationCollapse">
                    Documentation
                </button>
            </h2>
            <div id="assetIndexDocumentationCollapse" class="accordion-collapse collapse" aria-labelledby="assetIndexDocumentationHeader" data-bs-parent="#assetIndexDocumentationAccordion">
                <div class="accordion-body">
                    <p class="small text-muted">
                        Detailed user manual and technical documentation for the Asset Management module.
                    </p>
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#docModal">
                            <i class="bi bi-book me-1"></i> View Full Documentation
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================================================================
         DOCUMENTATION MODAL
         - Renders the assets.md documentation file within the UI
         ====================================================================== -->
    <div class="modal fade" id="docModal" tabindex="-1" aria-labelledby="docModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="docModalLabel">Asset Management Documentation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="markdown-body">
                        @php
                            // The documentation moved with the Asset module.
                            // Keeping this path module-local means the in-page
                            // docs preview and the `/assets/docs` response read
                            // from the same source file.
                            $docPath = app_path('Modules/Asset/Docs/legacy-view-specs/Tech/assets.md');
                            if (file_exists($docPath)) {
                                if (class_exists('\Parsedown')) {
                                    $parsedown = new \Parsedown();
                                    echo $parsedown->text(file_get_contents($docPath));
                                } else {
                                    echo '<div class="alert alert-warning small">Markdown parser not found. Displaying raw documentation:</div>';
                                    echo '<pre style="white-space: pre-wrap; font-size: 0.85rem;">' . e(file_get_contents($docPath)) . '</pre>';
                                }
                            } else {
                                echo '<div class="alert alert-danger">Documentation file not found.</div>';
                            }
                        @endphp
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="{{ route('tech.assets.docs') }}" class="btn btn-primary" target="_blank">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Open in New Tab
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
