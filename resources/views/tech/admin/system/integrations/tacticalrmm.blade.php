<?php
$integration = $integration ?? null;
$isHealthy = $integration?->is_healthy ?? false;
$lastError = $integration?->last_error;
$lastSync = $integration?->last_sync_at;
$agentCount = $stats['agent_count'] ?? 0;
$clientCount = $stats['client_count'] ?? 0;
?@>

@extends('layouts.default_tech')

@section('title', 'TacticalRMM Settings')

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.index') }}">Integrations</a></li>
            <li class="breadcrumb-item active" aria-current="page">TacticalRMM Settings</li>
        </ol>
    </nav>
    <h1>TacticalRMM Settings</h1>
@endsection

@section('content')
    <div class="container-fluid">

        {{-- Connection Status Card --}}
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card {{ $isHealthy ? 'border-success' : 'border-warning' }}">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Connection Status</h5>
                        @if($isHealthy)
                            <span class="badge bg-success"><i class="bi bi-check-circle"></i> Connected</span>
                        @else
                            <span class="badge bg-warning"><i class="bi bi-exclamation-triangle"></i> Disconnected</span>
                        @endif
                    </div>
                    <div class="card-body">
                        @if($isHealthy)
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h3 class="text-primary">{{ $agentCount }}</h3>
                                        <p class="text-muted">Agents</p>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h3 class="text-primary">{{ $clientCount }}</h3>
                                        <p class="text-muted">Clients</p>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h3 class="text-info">{{ $lastSync?->diffForHumans() ?? 'Never' }}</h3>
                                        <p class="text-muted">Last Sync</p>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h3 class="text-secondary">{{ $integration?->server ?? 'Not configured' }}</h3>
                                        <p class="text-muted">Server</p>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle"></i>
                                @if($lastError)
                                    {{ $lastError }}
                                @else
                                    Not connected to TacticalRMM. Please configure your API settings below.
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            {{-- API Configuration Form --}}
            <div class="col-md-6">
                <form action="{{ route('tech.admin.system.integrations.tacticalrmm.update') }}" method="POST">
                    @csrf
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">API Configuration</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="server" class="form-label">Server URL</label>
                                <input type="url" class="form-control" id="server" name="server" 
                                       value="{{ $integration->server ?? 'https://api.td-network.no' }}"
                                       placeholder="https://api.td-network.no">
                                <small class="text-muted">Enter the base URL for your TacticalRMM instance.</small>
                            </div>

                            <div class="mb-3">
                                <label for="api_key" class="form-label">API Key</label>
                                <input type="password" class="form-control" id="api_key" name="api_key" 
                                       autocomplete="new-password"
                                       placeholder="{{ $integration && $integration->getSecret('api_key') ? '••••••••••••••••' : 'Enter API Key' }}">
                                <small class="text-muted">Your TacticalRMM API key is stored encrypted in the database.</small>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Save API Config
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            {{-- Actions Card --}}
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Actions</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('tech.admin.system.integrations.tacticalrmm.sync') }}" method="POST" class="mb-3">
                            @csrf
                            <button type="submit" class="btn btn-success {{ !$isHealthy ? 'disabled' : '' }}">
                                <i class="bi bi-arrow-repeat"></i> Sync Now
                            </button>
                            <p class="text-muted small mt-2">Manually sync clients and agents from TacticalRMM.</p>
                        </form>

                        <hr>

                        <form action="{{ route('tech.admin.system.integrations.tacticalrmm.test') }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-outline-info">
                                <i class="bi bi-lightning"></i> Test Connection
                            </button>
                            <p class="text-muted small mt-2">Verify API connectivity without syncing data.</p>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- Automation Settings --}}
        <div class="row">
            <div class="col-md-12">
                <form action="{{ route('tech.admin.system.integrations.tacticalrmm.update_settings') }}" method="POST">
                    @csrf
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Automation Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" 
                                               id="client_sync_from" name="client_sync_from" value="1"
                                               {{ ($integration && isset($integration->config['client_sync_from']) && $integration->config['client_sync_from']) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="client_sync_from">
                                            Auto-sync clients from TacticalRMM
                                        </label>
                                    </div>
                                    <small class="text-muted d-block">
                                        Automatically create clients in Nexum when they exist in TacticalRMM.
                                    </small>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" 
                                               id="asset_sync_from" name="asset_sync_from" value="1"
                                               {{ ($integration && isset($integration->config['asset_sync_from']) && $integration->config['asset_sync_from']) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="asset_sync_from">
                                            Auto-sync agents from TacticalRMM
                                        </label>
                                    </div>
                                    <small class="text-muted d-block">
                                        Automatically create assets/agents in Nexum from TacticalRMM.
                                    </small>
                                </div>
                            </div>

                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Save Settings
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>
@endsection

@section('sidebar')

@endsection

@section('rightbar')

@endsection
