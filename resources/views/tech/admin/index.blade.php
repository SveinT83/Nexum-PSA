@extends('layouts.default_tech')

@section('title', 'Admin')

@php
    $adminSections = [
        [
            'title' => 'Commercial',
            'icon' => 'bi-briefcase',
            'description' => 'Configure contracts, service catalogue, rates, and commercial units.',
            'links' => [
                ['label' => 'Contracts', 'route' => route('tech.admin.settings.cs.contracts')],
                ['label' => 'Services', 'route' => route('tech.admin.settings.cs.services')],
                ['label' => 'Units', 'route' => route('tech.admin.settings.economy.units')],
            ],
        ],
        [
            'title' => 'Economy',
            'icon' => 'bi-receipt',
            'description' => 'Control order and billing settings used by ticket time and sales.',
            'links' => [
                ['label' => 'Orders and billing', 'route' => route('tech.admin.settings.economy')],
            ],
        ],
        [
            'title' => 'Email',
            'icon' => 'bi-envelope-at',
            'description' => 'Manage mail accounts, routing rules, parser config, and templates.',
            'links' => [
                ['label' => 'Accounts', 'route' => route('tech.admin.settings.email.accounts')],
                ['label' => 'Config', 'route' => route('tech.admin.settings.email.config')],
                ['label' => 'Rules', 'route' => route('tech.admin.settings.email.rules')],
                ['label' => 'Templates', 'route' => route('tech.admin.system.templatesManagement.email.index')],
            ],
        ],
        [
            'title' => 'Sales',
            'icon' => 'bi-graph-up-arrow',
            'description' => 'Tune sales rules, workflows, and opportunity behavior.',
            'links' => [
                ['label' => 'Rules', 'route' => route('tech.admin.settings.sales.rules')],
                ['label' => 'Workflows', 'route' => route('tech.admin.settings.sales.workflows')],
            ],
        ],
        [
            'title' => 'Clients',
            'icon' => 'bi-buildings',
            'description' => 'Client domain settings and reusable customer classifications.',
            'links' => [
                ['label' => 'Client formats', 'route' => route('tech.admin.settings.clients.client-formats')],
            ],
        ],
        [
            'title' => 'Storage',
            'icon' => 'bi-box-seam',
            'description' => 'Inventory administration, warehouse structure, and stock defaults.',
            'links' => [
                ['label' => 'Inventory settings', 'route' => route('tech.admin.settings.storage.inventory')],
            ],
        ],
        [
            'title' => 'Tickets',
            'icon' => 'bi-ticket-detailed',
            'description' => 'Queues, priorities, workflows, assignment logic, and ticket rules.',
            'links' => [
                ['label' => 'Ticket settings', 'route' => route('tech.admin.settings.tickets')],
                ['label' => 'Rules', 'route' => route('tech.admin.settings.tickets.rules')],
                ['label' => 'Workflows', 'route' => route('tech.admin.settings.tickets.workflows')],
            ],
        ],
        [
            'title' => 'Templates',
            'icon' => 'bi-layout-text-window-reverse',
            'description' => 'Reusable document and email templates used across modules.',
            'links' => [
                ['label' => 'Template management', 'route' => route('tech.admin.system.templatesManagement.index')],
            ],
        ],
        [
            'title' => 'Users',
            'icon' => 'bi-people',
            'description' => 'Users, roles, permissions, and account-level access settings.',
            'links' => [
                ['label' => 'User management', 'route' => route('tech.admin.user_management.index')],
            ],
        ],
        [
            'title' => 'System',
            'icon' => 'bi-sliders',
            'description' => 'Shared taxonomy, background workers, and platform settings.',
            'links' => [
                ['label' => 'Categories', 'route' => route('tech.admin.system.category.index')],
                ['label' => 'Tags', 'route' => route('tech.admin.system.tag.index')],
                ['label' => 'Queues and workers', 'route' => route('tech.admin.system.queues-workers.index')],
            ],
        ],
        [
            'title' => 'Integrations',
            'icon' => 'bi-plug',
            'description' => 'External systems, API access, AI providers, and sync connections.',
            'links' => [
                ['label' => 'All integrations', 'route' => route('tech.admin.system.integrations.index')],
                ['label' => 'API management', 'route' => route('tech.admin.system.integrations.api.index')],
            ],
        ],
    ];
@endphp

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">Admin</h1>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Admin settings hub -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3">
        @foreach($adminSections as $section)
            <div class="col-xxl-3 col-xl-4 col-md-6">
                <div class="card h-100 admin-hub-card shadow-sm">
                    <div class="card-header bg-body d-flex align-items-center gap-2">
                        <span class="admin-hub-icon flex-shrink-0">
                            <i class="bi {{ $section['icon'] }}" aria-hidden="true"></i>
                        </span>
                        <h2 class="h6 mb-0">{{ $section['title'] }}</h2>
                    </div>

                    <div class="card-body d-flex flex-column">
                        <p class="small text-muted mb-3">{{ $section['description'] }}</p>

                        <div class="row row-cols-2 g-2 mt-auto">
                            @foreach($section['links'] as $link)
                                <div class="col">
                                    <a href="{{ $link['route'] }}" class="btn btn-sm btn-outline-secondary w-100 h-100 admin-hub-action">
                                        {{ $link['label'] }}
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu />
@endsection

@section('rightbar')
@endsection

@section('scripts')
    <style>
        .admin-hub-card {
            border-color: rgba(0, 0, 0, .08);
        }

        .admin-hub-icon {
            width: 1.9rem;
            height: 1.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--bs-border-color);
            border-radius: .5rem;
            color: var(--bs-primary);
            background: var(--bs-tertiary-bg);
            font-size: .95rem;
        }

        .admin-hub-action {
            min-height: 2.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: normal;
            line-height: 1.2;
        }
    </style>
@endsection
