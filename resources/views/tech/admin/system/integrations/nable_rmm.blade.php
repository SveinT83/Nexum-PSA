@extends('layouts.default_tech')

@section('title', 'N-able RMM Settings')

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.index') }}">Integrations</a></li>
            <li class="breadcrumb-item active" aria-current="page">N-able RMM Settings</li>
        </ol>
    </nav>
    <h1>N-able RMM Settings</h1>
@endsection

@section('content')
    <div class="container-fluid">

        {{--
            Livewire Synchronization Component
            This component manages the batch processing and progress UI for
            manual client and site synchronization tasks.
        --}}
        <livewire:tech.admin.system.integrations.n-able-rmm-sync />

        <div class="row">
            <div class="col-md-12">
                {{--
                    Main Settings Form
                    This form handles both API configuration (Server, API Key)
                    and synchronization logic toggles.
                --}}
                <form action="{{ route('tech.admin.system.integrations.nable_rmm.update') }}" method="POST">
                    @csrf

                    {{-- API Configuration Card: Server Region and API Credentials --}}
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">API Configuration</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="server" class="form-label">Server Region</label>
                                <select class="form-select" id="server" name="server">
                                    {{-- Region selections based on N-able RMM's distributed architecture --}}
                                    <option value="https://www.am.remote.management/" {{ ($integration->server ?? '') == 'https://www.am.remote.management/' ? 'selected' : '' }}>Americas: https://www.am.remote.management/</option>
                                    <option value="https://wwwasia.system-monitor.com/" {{ ($integration->server ?? '') == 'https://wwwasia.system-monitor.com/' ? 'selected' : '' }}>Asia: https://wwwasia.system-monitor.com/</option>
                                    <option value="https://www.system-monitor.com/" {{ ($integration->server ?? '') == 'https://www.system-monitor.com/' ? 'selected' : '' }}>Australia: https://www.system-monitor.com/</option>
                                    <option value="https://wwweurope1.systemmonitor.eu.com/" {{ ($integration->server ?? 'https://wwweurope1.systemmonitor.eu.com/') == 'https://wwweurope1.systemmonitor.eu.com/' ? 'selected' : '' }}>Europe: https://wwweurope1.systemmonitor.eu.com/ (Default)</option>
                                    <option value="https://wwwfrance.systemmonitor.eu.com/" {{ ($integration->server ?? '') == 'https://wwwfrance.systemmonitor.eu.com/' ? 'selected' : '' }}>France (FR): https://wwwfrance.systemmonitor.eu.com/</option>
                                    <option value="https://wwwfrance1.systemmonitor.eu.com/" {{ ($integration->server ?? '') == 'https://wwwfrance1.systemmonitor.eu.com/' ? 'selected' : '' }}>France1: https://wwwfrance1.systemmonitor.eu.com/</option>
                                    <option value="https://wwwgermany1.systemmonitor.eu.com/" {{ ($integration->server ?? '') == 'https://wwwgermany1.systemmonitor.eu.com/' ? 'selected' : '' }}>Germany: https://wwwgermany1.systemmonitor.eu.com/</option>
                                    <option value="https://wwwireland.systemmonitor.eu.com/" {{ ($integration->server ?? '') == 'https://wwwireland.systemmonitor.eu.com/' ? 'selected' : '' }}>Ireland: https://wwwireland.systemmonitor.eu.com/</option>
                                    <option value="https://wwwpoland1.systemmonitor.eu.com/" {{ ($integration->server ?? '') == 'https://wwwpoland1.systemmonitor.eu.com/' ? 'selected' : '' }}>Poland: https://wwwpoland1.systemmonitor.eu.com/</option>
                                    <option value="https://www.systemmonitor.co.uk/" {{ ($integration->server ?? '') == 'https://www.systemmonitor.co.uk/' ? 'selected' : '' }}>United Kingdom: https://www.systemmonitor.co.uk/</option>
                                    <option value="https://www.systemmonitor.us/" {{ ($integration->server ?? '') == 'https://www.systemmonitor.us/' ? 'selected' : '' }}>United States: https://www.systemmonitor.us/</option>
                                </select>
                                <small class="text-muted">Select the regional server for your N-able RMM instance.</small>
                            </div>

                            <div class="mb-3">
                                <label for="api_key" class="form-label">API Key</label>
                                {{-- Input for API key; placeholder changes if a key is already saved --}}
                                <input type="password" class="form-control" id="api_key" name="api_key"
                                       placeholder="{{ $integration && $integration->getSecret('api_key') ? '********' : 'Enter API Key' }}">
                                <small class="text-muted">Enter a new API key to update it. It is stored encrypted in the database.</small>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary">Save Config</button>
                            </div>
                        </div>
                    </div>

                    {{-- Settings Card: Granular Synchronization Controls --}}
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Settings</h5>
                        </div>
                        <div class="card-body">
                            {{-- Configuration for automatic background client synchronization --}}
                            <h6 class="mb-3">Client Synchronization</h6>
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="client_sync_from"
                                           name="client_sync_from" value="1"
                                           {{ ($integration && isset($integration->config['client_sync_from']) && $integration->config['client_sync_from']) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="client_sync_from">Sync clients from RMM to system</label>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    Automatically create clients in our system if they exist in RMM but not here.
                                </small>
                            </div>

                            <div class="mb-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="client_sync_to"
                                           name="client_sync_to" value="1"
                                           {{ ($integration && isset($integration->config['client_sync_to']) && $integration->config['client_sync_to']) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="client_sync_to">Sync clients to RMM</label>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    Automatically create clients in RMM if they exist in our system but not in RMM.
                                </small>
                            </div>

                            {{-- Configuration for automatic background site synchronization --}}
                            <h6 class="mb-3">Site Synchronization</h6>
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="site_sync_from"
                                           name="site_sync_from" value="1"
                                           {{ ($integration && isset($integration->config['site_sync_from']) && $integration->config['site_sync_from']) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="site_sync_from">Import sites from RMM</label>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    Sync sites from N-able RMM into our system for linked clients.
                                </small>
                            </div>

                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="site_sync_to"
                                           name="site_sync_to" value="1"
                                           {{ ($integration && isset($integration->config['site_sync_to']) && $integration->config['site_sync_to']) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="site_sync_to">Export sites to RMM</label>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    Create sites in N-able RMM from our system for linked clients.
                                </small>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary">Save Settings</button>
                            </div>
                        </div>
                    </div>
                </form>

                {{--
                    Manual Synchronization Card
                    Triggers immediate, foreground synchronization using Livewire.
                    Each action corresponds to a specific RMM API endpoint.
                --}}
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Manual Synchronization</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            {{-- Import Clients Row --}}
                            <div class="col-md-6 text-center border-end">
                                <h6>Sync client from RMM</h6>
                                <p class="text-muted small">Fetch new clients from N-able RMM.</p>
                                <button type="button" class="btn btn-outline-info" onclick="Livewire.dispatch('startSync', { type: 'clients_from' })">
                                    <i class="fas fa-download"></i> Sync Clients from RMM
                                </button>
                                <div class="mt-2 small text-muted">
                                    Endpoint: <code>/api/?service=list_clients</code>
                                </div>
                            </div>
                            {{-- Export Clients Row --}}
                            <div class="col-md-6 text-center">
                                <h6>Sync clients to RMM</h6>
                                <p class="text-muted small">Add local clients to your N-able RMM instance.</p>
                                <button type="button" class="btn btn-outline-warning" onclick="Livewire.dispatch('startSync', { type: 'clients_to' })">
                                    <i class="fas fa-upload"></i> Sync Clients to RMM
                                </button>
                                <div class="mt-2 small text-muted">
                                    Endpoint: <code>/api/?service=add_client</code>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="row mt-4">
                            {{-- Import Sites Row --}}
                            <div class="col-md-6 text-center border-end">
                                <h6>Sync sites from RMM</h6>
                                <p class="text-muted small">Fetch sites for linked clients from N-able RMM.</p>
                                <button type="button" class="btn btn-outline-info" onclick="Livewire.dispatch('startSync', { type: 'sites_from' })">
                                    <i class="fas fa-download"></i> Sync Sites from RMM
                                </button>
                                <div class="mt-2 small text-muted">
                                    Endpoint: <code>/api/?service=list_sites</code>
                                </div>
                            </div>
                            {{-- Export Sites Row --}}
                            <div class="col-md-6 text-center">
                                <h6>Sync sites to RMM</h6>
                                <p class="text-muted small">Add local sites to linked N-able RMM clients.</p>
                                <button type="button" class="btn btn-outline-warning" onclick="Livewire.dispatch('startSync', { type: 'sites_to' })">
                                    <i class="fas fa-upload"></i> Sync Sites to RMM
                                </button>
                                <div class="mt-2 small text-muted">
                                    Endpoint: <code>/api/?service=add_site</code>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        /**
         * Global listener for synchronization confirmation.
         * Dispatched by the Livewire component when a manual sync is requested.
         */
        window.addEventListener('confirmSync', event => {
            if(confirm('Are you sure you want to start this synchronization?')) {
                Livewire.dispatch(event.detail.type);
            }
        });
    </script>
@endsection

@section('sidebar')

@endsection

@section('rightbar')

    {{-- Status Overview Card: Real-time integration health and sync metrics --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Status Overview</h5>
        </div>
        <div class="card-body">
            <ul class="list-group list-group-flush">
                {{-- Master toggle status --}}
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Integration Status
                    <span class="badge bg-{{ ($integration && $integration->status === 'active') ? 'success' : 'secondary' }}">
                                    {{ $integration ? ucfirst($integration->status) : 'Not Initialized' }}
                                </span>
                </li>
                {{-- Connection health based on the last successful API handshake --}}
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Connection Health
                    <span class="badge bg-{{ ($integration && $integration->is_healthy) ? 'success' : 'danger' }}">
                                    {{ ($integration && $integration->is_healthy) ? 'Healthy' : 'Error' }}
                                </span>
                </li>
                {{-- Relative time since the last synchronization task completed --}}
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Last Sync
                    <span class="text-muted">{{ ($integration && $integration->last_sync_at) ? $integration->last_sync_at->diffForHumans() : 'Never' }}</span>
                </li>
            </ul>
        </div>
    </div>

    {{-- Detailed error reporting for the last failed operation --}}
    @if($integration && $integration->last_error)
        <div class="alert alert-danger">
            <h6>Last Error:</h6>
            <p class="small mb-0">{{ $integration->last_error }}</p>
        </div>
    @endif

    {{--
        Documentation Card
        Provides a summary of requirements and access to the full integration guide.
    --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Documentation</h5>
            <i class="bi bi-info-circle"></i>
        </div>
        <div class="card-body">
            <p class="small text-muted">
                This integration synchronizes clients and sites between tdPSA and N-able RMM.
            </p>
            <h6 class="small fw-bold">Automation Setup:</h6>
            <p class="x-small text-muted mb-2">
                Requires <code>schedule:run</code> and <code>queue:work</code> to be active on the server.
            </p>
            <div class="d-grid gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#docModal">
                    <i class="bi bi-book me-1"></i> View Full Documentation
                </button>
            </div>
        </div>
    </div>

    {{--
        Documentation Modal
        Renders the external Markdown documentation file (nable_rmm_doc.md)
        within the UI using the Parsedown library.
    --}}
    <div class="modal fade" id="docModal" tabindex="-1" aria-labelledby="docModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="docModalLabel">N-able RMM Integration Guide</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="markdown-body">
                        @php
                            // Path to the Markdown documentation file
                            $docPath = resource_path('views/tech/admin/system/integrations/nable_rmm_doc.md');
                            if (file_exists($docPath)) {
                                // Attempt to parse Markdown using Parsedown
                                if (class_exists('\Parsedown')) {
                                    $parsedown = new \Parsedown();
                                    echo $parsedown->text(file_get_contents($docPath));
                                } else {
                                    // Robust fallback if the library is missing
                                    echo '<div class="alert alert-warning small">Markdown parser not found. Displaying raw documentation:</div>';
                                    echo '<pre style="white-space: pre-wrap; font-size: 0.85rem;">' . e(file_get_contents($docPath)) . '</pre>';
                                }
                            } else {
                                echo "Documentation file not found.";
                            }
                        @endphp
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .x-small { font-size: 0.75rem; }
    </style>
@endsection
