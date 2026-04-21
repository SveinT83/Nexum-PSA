@extends('layouts.default_tech')

@section('title', 'Integrations')

@section('pageHeader')
    <h1>Integrations</h1>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            {{--
                N-able RMM Integration Card
                This card allows administrators to enable/disable the N-able RMM integration
                and provides access to its specific settings if activated.
            --}}
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">N-able RMM</h5>
                        @php
                            // Check if the integration record exists and is active
                            $nable = $integrations->get('rmm');
                            $isActive = $nable && $nable->status === 'active';
                        @endphp

                        {{-- Toggle Switch for Integration Status --}}
                        <form action="{{ route('tech.admin.system.integrations.toggle') }}" method="POST">
                            @csrf
                            <input type="hidden" name="type" value="rmm">
                            <input type="hidden" name="name" value="N-able RMM">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="toggleNable" onchange="this.form.submit()"
                                       {{ $isActive ? 'checked' : '' }}>
                                <label class="form-check-label" for="toggleNable">
                                    {{ $isActive ? 'Enabled' : 'Disabled' }}
                                </label>
                            </div>
                        </form>
                    </div>
                    <div class="card-body">
                        <p class="card-text">Integrate with N-able RMM to sync clients, sites, and assets.</p>

                        @if($isActive)
                            {{-- Settings are only accessible when the integration is enabled --}}
                            <a href="{{ route('tech.admin.system.integrations.nable_rmm.settings') }}" class="btn btn-primary">
                                <i class="bi bi-gear"></i> Settings
                            </a>
                        @else
                            {{-- Disabled settings button with a helpful hint --}}
                            <button class="btn btn-secondary" disabled>
                                <i class="bi bi-gear"></i> Settings
                            </button>
                            <p class="text-muted small mt-2">Enable the integration to configure settings and API key.</p>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">System API</h5>
                        <span class="badge bg-success">Active</span>
                    </div>
                    <div class="card-body">
                        <p class="card-text">Manage API keys and access system documentation.</p>
                        <a href="{{ route('tech.admin.system.integrations.api.index') }}" class="btn btn-primary">
                            <i class="bi bi-shield-lock"></i> API Management
                        </a>
                        <a href="{{ route('tech.admin.system.integrations.api.docs') }}" class="btn btn-outline-primary ms-2" target="_blank">
                            <i class="bi bi-file-earmark-code"></i> Docs
                        </a>
                    </div>
                </div>
            </div>

            {{--
                Future Integrations Placeholder
                Additional integration cards can be added here following the same pattern.
            --}}
            <div class="col-md-4">
                <div class="card opacity-50">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">OpenAI</h5>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" disabled>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="card-text">AI integration for smart features (Coming soon).</p>
                        <button class="btn btn-secondary" disabled>Settings</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')

@endsection

@section('rightbar')

@endsection
