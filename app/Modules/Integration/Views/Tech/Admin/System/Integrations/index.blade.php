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
            <div class="col-md-4 mb-4">
                <div class="card h-100">
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
                    <div class="card-body d-flex flex-column">
                        <p class="card-text">Integrate with N-able RMM to sync clients, sites, and assets.</p>

                        <div class="mt-auto">
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
                                <p class="text-muted small mt-2 mb-0">Enable the integration to configure settings and API key.</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">BookStack</h5>
                        @php
                            $bookStack = $integrations->get('book_stack');
                            $isBookStackActive = $bookStack && $bookStack->status === 'active';
                        @endphp

                        <form action="{{ route('tech.admin.system.integrations.toggle') }}" method="POST">
                            @csrf
                            <input type="hidden" name="type" value="book_stack">
                            <input type="hidden" name="name" value="BookStack">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="toggleBookStack" onchange="this.form.submit()"
                                       {{ $isBookStackActive ? 'checked' : '' }}>
                                <label class="form-check-label" for="toggleBookStack">
                                    {{ $isBookStackActive ? 'Enabled' : 'Disabled' }}
                                </label>
                            </div>
                        </form>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <p class="card-text">Use BookStack as the read-only source of truth for knowledge content.</p>

                        <div class="mt-auto">
                            @if($isBookStackActive)
                                <a href="{{ route('tech.admin.system.integrations.book_stack.settings') }}" class="btn btn-primary">
                                    <i class="bi bi-gear"></i> Settings
                                </a>
                            @else
                                <button class="btn btn-secondary" disabled>
                                    <i class="bi bi-gear"></i> Settings
                                </button>
                                <p class="text-muted small mt-2 mb-0">Enable the integration to configure BookStack API credentials.</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Tactical RMM</h5>
                        @php
                            $tactical = $integrations->get('tactical_rmm');
                            $isTacticalActive = $tactical && $tactical->status === 'active';
                        @endphp

                        <form action="{{ route('tech.admin.system.integrations.toggle') }}" method="POST">
                            @csrf
                            <input type="hidden" name="type" value="tactical_rmm">
                            <input type="hidden" name="name" value="Tactical RMM">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="toggleTactical" onchange="this.form.submit()"
                                       {{ $isTacticalActive ? 'checked' : '' }}>
                                <label class="form-check-label" for="toggleTactical">
                                    {{ $isTacticalActive ? 'Enabled' : 'Disabled' }}
                                </label>
                            </div>
                        </form>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <p class="card-text">Integrate with Tactical RMM to sync clients, sites, and assets.</p>

                        <div class="mt-auto">
                            @if($isTacticalActive)
                                <a href="{{ route('tech.admin.system.integrations.tactical_rmm.settings') }}" class="btn btn-primary">
                                    <i class="bi bi-gear"></i> Settings
                                </a>
                            @else
                                <button class="btn btn-secondary" disabled>
                                    <i class="bi bi-gear"></i> Settings
                                </button>
                                <p class="text-muted small mt-2 mb-0">Enable the integration to configure settings and API key.</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">System API</h5>
                        <span class="badge bg-success">Active</span>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <p class="card-text">Manage API keys and access system documentation.</p>
                        <div class="mt-auto">
                            <a href="{{ route('tech.admin.system.integrations.api.index') }}" class="btn btn-primary">
                                <i class="bi bi-shield-lock"></i> API Management
                            </a>
                            <a href="{{ route('tech.admin.system.integrations.api.docs') }}" class="btn btn-outline-primary ms-2" target="_blank">
                                <i class="bi bi-file-earmark-code"></i> Docs
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">AI Providers</h5>
                        <span class="badge bg-light text-dark border">Config</span>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <p class="card-text">Configure AI providers, agent instructions, role access, data scopes, and future API action permissions.</p>
                        <div class="mt-auto">
                            <a href="{{ route('tech.admin.system.integrations.ai.index') }}" class="btn btn-primary">
                                <i class="bi bi-cpu"></i> AI Settings
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            @if(Route::has('tech.admin.nextcloud.connections.index'))
                <div class="col-md-4 mt-4">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Nextcloud</h5>
                            <span class="badge bg-light text-dark border">Domain</span>
                        </div>
                        <div class="card-body d-flex flex-column">
                            <p class="card-text">Configure global, client, and site Nextcloud connections for calendars, files, users, groups, and future mappings.</p>
                            <div class="mt-auto">
                                <a href="{{ route('tech.admin.nextcloud.connections.index') }}" class="btn btn-primary">
                                    <i class="bi bi-cloud"></i> Nextcloud Settings
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

@section('sidebar')

@endsection

@section('rightbar')

@endsection
