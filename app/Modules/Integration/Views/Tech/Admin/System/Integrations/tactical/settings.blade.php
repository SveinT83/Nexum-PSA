@extends('layouts.default_tech')

@section('title', 'Tactical RMM Settings')

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.index') }}">Integrations</a></li>
            <li class="breadcrumb-item active" aria-current="page">Tactical RMM Settings</li>
        </ol>
    </nav>
    <h1>Tactical RMM Settings</h1>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                {{-- API Configuration Form --}}
                <form action="{{ route('tech.admin.system.integrations.tactical_rmm.update') }}" method="POST">
                    @csrf
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">API Configuration</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="server" class="form-label">API URL</label>
                                <input type="url" class="form-control" id="server" name="server"
                                       value="{{ $integration->server ?? '' }}" placeholder="https://api.example.com" required>
                                <small class="text-muted">Enter the base URL for your Tactical RMM API (e.g., https://api.yourdomain.com).</small>
                            </div>

                            <div class="mb-3">
                                <label for="api_key" class="form-label">API Key</label>
                                <input type="password" class="form-control" id="api_key" name="api_key" autocomplete="new-password"
                                       placeholder="{{ $integration && $integration->getSecret('api_key') ? '********' : 'Enter API Key' }}">
                                <small class="text-muted">Enter your Tactical RMM API Key. It is stored encrypted in the database.</small>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary">Save & Test Connection</button>
                            </div>
                        </div>
                    </div>
                </form>

                {{-- Automation Settings Form --}}
                <form action="{{ route('tech.admin.system.integrations.tactical_rmm.update_settings') }}" method="POST">
                    @csrf
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Automation Settings</h5>
                        </div>
                        <div class="card-body">
                            <h6 class="mb-3">Synchronization Controls</h6>

                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="client_sync_from"
                                           name="client_sync_from" value="1"
                                           {{ ($integration && isset($integration->config['client_sync_from']) && $integration->config['client_sync_from']) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="client_sync_from">Sync clients and sites from Tactical RMM</label>
                                </div>
                                <small class="text-muted d-block mt-1">Automatically import and update clients and their sites from Tactical RMM.</small>
                            </div>

                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="asset_sync_from"
                                           name="asset_sync_from" value="1"
                                           {{ ($integration && isset($integration->config['asset_sync_from']) && $integration->config['asset_sync_from']) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="asset_sync_from">Import assets from Tactical RMM</label>
                                </div>
                                <small class="text-muted d-block mt-1">Automatically import and update assets (agents).</small>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary">Save Automation Settings</button>
                            </div>
                        </div>
                    </div>
                </form>

                {{-- Manual Synchronization Card --}}
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Manual Synchronization</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card bg-light border-0">
                                    <div class="card-body">
                                        <h6>Sync Clients and sites</h6>
                                        <p class="small text-muted">Fetch and link clients from Tactical RMM.</p>
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick="Livewire.dispatch('startTacticalSync', { params: { type: 'clients_from' } })">
                                            Sync Clients from RMM
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card bg-light border-0">
                                    <div class="card-body">
                                        <h6>Sync Assets</h6>
                                        <p class="small text-muted">Import and update assets from Tactical RMM.</p>
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick="Livewire.dispatch('startTacticalSync', { params: { type: 'assets_from' } })">
                                            Sync Assets from RMM
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('rightbar')

    {{-- Status Overview Card --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Status Overview</h5>
        </div>
        <div class="card-body">
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Integration Status
                    <span class="badge bg-{{ ($integration && $integration->status === 'active') ? 'success' : 'secondary' }}">
                        {{ $integration ? ucfirst($integration->status) : 'Not Initialized' }}
                    </span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Connection Health
                    @if(!$integration || $integration->is_healthy === null || (!$integration->is_healthy && !$integration->last_error))
                        <span class="badge bg-warning">Pending</span>
                    @elseif($integration->is_healthy)
                        <span class="badge bg-success">Healthy</span>
                    @else
                        <span class="badge bg-danger">Error</span>
                    @endif
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Last Sync
                    <span class="text-muted">{{ ($integration && $integration->last_sync_at) ? $integration->last_sync_at->diffForHumans() : 'Never' }}</span>
                </li>
            </ul>
        </div>
    </div>

    {{-- Error reporting --}}
    @if($integration && $integration->last_error)
        <div class="alert alert-danger">
            <h6>Last Error:</h6>
            <p class="small mb-0">{{ $integration->last_error }}</p>
        </div>
    @endif

    {{-- Documentation Card (Minimized) --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Documentation</h5>
            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" data-bs-toggle="modal" data-bs-target="#docModal">
                <i class="bi bi-info-circle"></i>
            </button>
        </div>

        <div class="card-body text-center">
            <p>This system handles the synchronization of clients, sites, and assets between Nexum PSA and Tactical RMM.</p>

            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#docModal">
                <i class="bi bi-info-circle"></i> View Documentation
            </button>
        </div>
    </div>

    {{-- Documentation Modal --}}
    <div class="modal fade" id="docModal" tabindex="-1" aria-labelledby="docModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="docModalLabel">Tactical RMM Integration Guide</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="markdown-body">
                        @php
                            $docPath = app_path('Modules/Integration/Docs/legacy-view-specs/Tech/Admin/System/Integrations/tactical/tactical_rmm_doc.md');
                            if (file_exists($docPath)) {
                                if (class_exists('\Parsedown')) {
                                    $parsedown = new \Parsedown();
                                    echo $parsedown->text(file_get_contents($docPath));
                                } else {
                                    echo '<div class="alert alert-warning small">Markdown parser not found. Raw doc:</div>';
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
@endsection
