@extends('layouts.default_tech')

@section('title', 'Assets')

@section('pageHeader')
    <!-- ======================================================================
         PAGE HEADER
         - Dynamic Title based on Client context
         - Action buttons (Back to Client, Create Asset)
         ====================================================================== -->
    <div class="d-flex justify-content-between align-items-center">
        <h1>
            @if($client)
                Assets for {{ $client->name }}
            @else
                Assets
            @endif
        </h1>
        <div class="btn-group">
            @if($client)
                <x-buttons.back :url="route('tech.clients.show', $client->id)">Back to Client</x-buttons.back>
                @php
                    $rmmIntegration = \App\Models\System\Integrations\Integration::where('type', 'rmm')->where('status', 'active')->first();
                    $tacticalIntegration = \App\Models\System\Integrations\Integration::where('type', 'tactical_rmm')->where('status', 'active')->first();

                    $canSyncNable = $rmmIntegration && $client->rmmLinks()->where('integration_id', $rmmIntegration->id)->exists();
                    $canSyncTactical = $tacticalIntegration && $client->rmmLinks()->where('integration_id', $tacticalIntegration->id)->exists();
                @endphp
                @if($rmmIntegration)
                    <button type="button"
                            class="btn btn-outline-primary"
                            @if(!$canSyncNable) disabled title="Client not linked to N-able RMM" @endif
                            onclick="Livewire.dispatch('startTargetedSync', { params: { type: 'assets_from', client_id: {{ $client->id }} } })">
                        <i class="bi bi-arrow-repeat me-1"></i> Sync Assets (N-able)
                    </button>
                @endif
                @if($tacticalIntegration)
                    <button type="button"
                            class="btn btn-outline-info"
                            @if(!$canSyncTactical) disabled title="Client not linked to Tactical RMM" @endif
                            onclick="Livewire.dispatch('startTargetedTacticalSync', { params: { type: 'assets_from', client_id: {{ $client->id }} } })">
                        <i class="bi bi-arrow-repeat me-1"></i> Sync Assets (Tactical)
                    </button>
                @endif
            @endif
            <a href="{{ route('tech.assets.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Create Asset
            </a>
            @if($client)
                <button type="button" class="btn btn-outline-warning" onclick="Livewire.dispatchTo('tech.work.assets.client-alerts-summary', 'syncAlerts')">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Sync Alerts
                </button>
            @endif
        </div>
    </div>
@endsection

@section('content')
    <!-- ======================================================================
         FILTERS SECTION
         - Allows filtering by Client (if global view), Type, and Status
         ====================================================================== -->
    <div class="card mb-4">
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
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="has_alerts" value="1" id="has_alerts" {{ request('has_alerts') ? 'checked' : '' }}>
                        <label class="form-check-label text-muted small fw-bold text-uppercase" for="has_alerts">
                            With Active Alerts
                        </label>
                    </div>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
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
                            <th class="ps-3">Name / Hostname</th>
                            <th>Type</th>
                            <th>Client / Site</th>
                            <th>Status</th>
                            <th>Last Seen</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($assets as $asset)
                            <tr>
                                <td class="ps-3">
                                    <a href="{{ route('tech.assets.show', ['asset' => $asset->id, 'tab' => 'summary']) }}" class="fw-bold text-decoration-none">
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
                                        <div class="small text-muted text-opacity-50">- No Site -</div>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $activeAlertsCount = $asset->alerts()->where('status', 'active')->count();
                                    @endphp
                                    @if($activeAlertsCount > 0)
                                        <a href="{{ route('tech.assets.show', $asset->id) }}#alerts" class="badge bg-danger text-decoration-none">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i> {{ $activeAlertsCount }} Alerts
                                        </a>
                                    @else
                                        <span class="badge bg-light text-dark border">
                                            Online
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <small>{{ $asset->last_seen_at ? $asset->last_seen_at->diffForHumans() : 'Never' }}</small>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="btn-group btn-group-sm">
                                        <a href="{{ route('tech.assets.show', $asset->id) }}" class="btn btn-outline-primary">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                        <a href="{{ route('tech.assets.edit', $asset->id) }}" class="btn btn-outline-secondary">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </div>
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

@section('rightbar')

    <!-- Alerts Summary Widget -->
    @livewire('tech.work.assets.client-alerts-summary', ['client' => $client])

    <!-- ======================================================================
         DOCUMENTATION CARD
         - Provides a summary of the module and a link to the full documentation
         ====================================================================== -->
    <div class="card border-info mb-4">
        <div class="card-header d-flex justify-content-between align-items-center bg-info text-white">
            <h5 class="mb-0">Documentation</h5>
            <i class="bi bi-info-circle"></i>
        </div>
        <div class="card-body">
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
                            $docPath = app_path('Modules/Asset/Views/Tech/assets.md');
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
