@extends('layouts.default_tech')

@section('title', 'Integrations')

@section('pageHeader')
    <h1 class="h4 mb-0">Integrations</h1>
@endsection

@section('content')
    @php
        /*
         * Keep the integration hub cards driven by one array so headers, actions,
         * and status treatment stay uniform as new integrations are added.
         */
        $integrationCards = [
            [
                'title' => 'N-able RMM',
                'icon' => 'bi-hdd-network',
                'description' => 'Sync clients, sites, and assets from N-able RMM.',
                'type' => 'rmm',
                'name' => 'N-able RMM',
                'settingsRoute' => 'tech.admin.system.integrations.nable_rmm.settings',
                'disabledHelp' => 'Enable the integration to configure settings and API key.',
            ],
            [
                'title' => 'BookStack',
                'icon' => 'bi-book',
                'description' => 'Use BookStack as the read-only source of truth for knowledge content.',
                'type' => 'book_stack',
                'name' => 'BookStack',
                'settingsRoute' => 'tech.admin.system.integrations.book_stack.settings',
                'disabledHelp' => 'Enable the integration to configure BookStack API credentials.',
            ],
            [
                'title' => 'Cloud Factory',
                'icon' => 'bi-cloud-check',
                'description' => 'Two-way Clients, catalogue, Microsoft/Adobe licences, contracts, and billing.',
                'type' => 'cloudfactory',
                'name' => 'Cloud Factory',
                'settingsRoute' => 'tech.admin.system.integrations.cloudfactory.index',
                'disabledHelp' => 'Enable the integration before connecting its dedicated Portal service account.',
            ],
            [
                'title' => 'Tactical RMM',
                'icon' => 'bi-router',
                'description' => 'Sync clients, sites, and assets from Tactical RMM.',
                'type' => 'tactical_rmm',
                'name' => 'Tactical RMM',
                'settingsRoute' => 'tech.admin.system.integrations.tactical_rmm.settings',
                'disabledHelp' => 'Enable the integration to configure settings and API key.',
            ],
            [
                'title' => 'System API',
                'icon' => 'bi-shield-lock',
                'description' => 'Manage API keys and access system documentation.',
                'badge' => ['label' => 'Active', 'class' => 'text-bg-success'],
                'actions' => [
                    ['label' => 'API Management', 'icon' => 'bi-shield-lock', 'route' => 'tech.admin.system.integrations.api.index'],
                    ['label' => 'Docs', 'icon' => 'bi-file-earmark-code', 'route' => 'tech.admin.system.integrations.api.docs', 'target' => '_blank'],
                ],
            ],
            [
                'title' => 'AI Providers',
                'icon' => 'bi-cpu',
                'description' => 'Configure AI providers, agent instructions, role access, data scopes, and future API action permissions.',
                'badge' => ['label' => 'Config', 'class' => 'text-bg-light border'],
                'actions' => [
                    ['label' => 'AI Settings', 'icon' => 'bi-cpu', 'route' => 'tech.admin.system.integrations.ai.index'],
                ],
            ],
        ];

        if (Route::has('tech.admin.nextcloud.connections.index')) {
            $integrationCards[] = [
                'title' => 'Nextcloud',
                'icon' => 'bi-cloud',
                'description' => 'Configure global, client, and site Nextcloud connections for calendars, files, users, groups, and mappings.',
                'badge' => ['label' => 'Domain', 'class' => 'text-bg-light border'],
                'actions' => [
                    ['label' => 'Nextcloud Settings', 'icon' => 'bi-cloud', 'route' => 'tech.admin.nextcloud.connections.index'],
                ],
            ];
        }
    @endphp

    <div class="row g-3">
        @foreach($integrationCards as $card)
            @php
                $record = isset($card['type']) ? $integrations->get($card['type']) : null;
                $isToggleable = isset($card['type']);
                $isActive = $isToggleable && $record?->status === 'active';
                $healthLabel = $record?->is_healthy === true ? 'Healthy' : ($record?->is_healthy === false ? 'Needs check' : null);
                $healthClass = $record?->is_healthy === true ? 'text-bg-success' : 'text-bg-warning';
            @endphp

            <!-- Integration card -->
            <div class="col-sm-6 col-xl-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-header py-2 d-flex align-items-center justify-content-between gap-2">
                        <h2 class="h6 mb-0 d-flex align-items-center gap-2">
                            <i class="bi {{ $card['icon'] }}" aria-hidden="true"></i>
                            <span>{{ $card['title'] }}</span>
                        </h2>

                        @if($isToggleable)
                            <form action="{{ route('tech.admin.system.integrations.toggle') }}" method="POST" class="m-0">
                                @csrf
                                <input type="hidden" name="type" value="{{ $card['type'] }}">
                                <input type="hidden" name="name" value="{{ $card['name'] }}">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="toggleIntegration{{ $loop->index }}" onchange="this.form.submit()"
                                           {{ $isActive ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="toggleIntegration{{ $loop->index }}">
                                        {{ $isActive ? 'Enabled' : 'Disabled' }}
                                    </label>
                                </div>
                            </form>
                        @else
                            <span class="badge {{ $card['badge']['class'] }}">{{ $card['badge']['label'] }}</span>
                        @endif
                    </div>

                    <div class="card-body d-flex flex-column">
                        <p class="card-text mb-3">{{ $card['description'] }}</p>

                        <div class="small text-muted mb-3">
                            @if($isToggleable)
                                <span class="badge {{ $isActive ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $isActive ? 'Enabled' : 'Disabled' }}</span>
                                @if($healthLabel)
                                    <span class="badge {{ $healthClass }}">{{ $healthLabel }}</span>
                                @endif
                            @else
                                <span class="badge {{ $card['badge']['class'] }}">{{ $card['badge']['label'] }}</span>
                            @endif
                        </div>

                        <div class="mt-auto d-grid gap-2">
                            @if($isToggleable)
                                @if($isActive)
                                    <a href="{{ route($card['settingsRoute']) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-gear" aria-hidden="true"></i>
                                        Settings
                                    </a>
                                @else
                                    <button class="btn btn-sm btn-outline-secondary" disabled>
                                        <i class="bi bi-gear" aria-hidden="true"></i>
                                        Settings
                                    </button>
                                    <div class="small text-muted">{{ $card['disabledHelp'] }}</div>
                                @endif
                            @else
                                @foreach($card['actions'] as $action)
                                    <a href="{{ route($action['route']) }}"
                                       class="btn btn-sm btn-outline-primary"
                                       @if(($action['target'] ?? null) === '_blank') target="_blank" rel="noopener" @endif>
                                        <i class="bi {{ $action['icon'] }}" aria-hidden="true"></i>
                                        {{ $action['label'] }}
                                    </a>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

@section('rightbar')

@endsection
