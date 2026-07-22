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
            'title' => 'Marketing',
            'icon' => 'bi-megaphone',
            'description' => 'Control campaign consent, unsubscribe behavior, tracking defaults, and send batching.',
            'links' => [
                ['label' => 'Marketing settings', 'route' => route('tech.admin.settings.marketing')],
                ['label' => 'Email accounts', 'route' => route('tech.admin.settings.email.accounts')],
                ['label' => 'Email templates', 'route' => route('tech.admin.system.templatesManagement.email.index')],
            ],
        ],
        [
            'title' => 'Lead Intelligence',
            'icon' => 'bi-search-heart',
            'description' => 'Configure prospecting policy, segment targeting, planned research runs, and suppression-aware contact eligibility.',
            'links' => [
                ['label' => 'Settings', 'route' => route('tech.admin.settings.lead-intelligence')],
                ['label' => 'Segments', 'route' => route('tech.lead-intelligence.segments.index')],
                ['label' => 'Research runs', 'route' => route('tech.lead-intelligence.runs.index')],
                ['label' => 'Scan ledger', 'route' => route('tech.lead-intelligence.scan-ledger.index')],
            ],
        ],
        [
            'title' => 'Clients',
            'icon' => 'bi-buildings',
            'description' => 'Client and contact domain settings and reusable customer classifications.',
            'links' => [
                ['label' => 'Client formats', 'route' => route('tech.admin.settings.clients.client-formats')],
                ['label' => 'Contact settings', 'route' => route('tech.admin.settings.contacts')],
            ],
        ],
        [
            'title' => 'Calendar',
            'icon' => 'bi-calendar-week',
            'description' => 'Shared calendars, default calendar behavior, access, privacy, and scheduling rules.',
            'links' => [
                ['label' => 'Calendar settings', 'route' => route('tech.admin.settings.calendar')],
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
            'title' => 'Assets',
            'icon' => 'bi-hdd-network',
            'description' => 'Configure manual asset registration defaults and available asset types.',
            'links' => [
                ['label' => 'Asset settings', 'route' => route('tech.admin.settings.assets')],
            ],
        ],
        [
            'title' => 'Tickets',
            'icon' => 'bi-ticket-detailed',
            'description' => 'Queues, priorities, workflows, assignment logic, task defaults, and ticket rules.',
            'links' => [
                ['label' => 'Ticket settings', 'route' => route('tech.admin.settings.tickets')],
                ['label' => 'Task settings', 'route' => route('tech.admin.settings.tasks')],
                ['label' => 'Technicians', 'route' => route('tech.admin.settings.tickets.technicians')],
                ['label' => 'Assignment rules', 'route' => route('tech.admin.settings.tickets.assignment-rules')],
                ['label' => 'Rules', 'route' => route('tech.admin.settings.tickets.rules')],
                ['label' => 'Workflows', 'route' => route('tech.admin.settings.tickets.workflows')],
            ],
        ],
        [
            'title' => 'Templates',
            'icon' => 'bi-layout-text-window-reverse',
            'description' => 'Reusable document, email, and Knowledge defaults used across modules.',
            'links' => [
                ['label' => 'Knowledge settings', 'route' => route('tech.admin.settings.knowledge')],
                ['label' => 'Template management', 'route' => route('tech.admin.system.templatesManagement.index')],
            ],
        ],
        [
            'title' => 'Users',
            'icon' => 'bi-people',
            'description' => 'Users, roles, permissions, and account-level access settings.',
            'links' => [
                ['label' => 'User management', 'route' => route('tech.admin.user_management.index')],
                ['label' => 'Roles', 'route' => route('tech.admin.user_management.roles.index')],
                ['label' => 'Permissions', 'route' => route('tech.admin.user_management.permissions.index')],
                ['label' => 'Two-factor auth', 'route' => route('tech.admin.user_management.2fa-settings')],
            ],
        ],
        [
            'title' => 'System',
            'icon' => 'bi-sliders',
            'description' => 'Company profile, branding, shared taxonomy, background workers, and platform settings.',
            'links' => [
                ['label' => 'Company profile', 'route' => route('tech.admin.system.company-profile.edit')],
                ['label' => 'Branding', 'route' => route('tech.admin.system.branding.edit')],
                ['label' => 'Warroom', 'route' => route('tech.admin.settings.warroom')],
                ['label' => 'Risk settings', 'route' => route('tech.admin.settings.risk')],
                ['label' => 'Custom fields', 'route' => route('tech.admin.settings.custom-fields.index')],
                ['label' => 'Categories', 'route' => route('tech.admin.system.category.index')],
                ['label' => 'Tags', 'route' => route('tech.admin.system.tag.index')],
                ['label' => 'Queues and workers', 'route' => route('tech.admin.system.queues-workers.index')],
                ['label' => 'Notification channels', 'route' => route('tech.admin.notification-channels.index')],
                ['label' => 'Signal feed', 'route' => route('tech.admin.system.signals.index')],
                ['label' => 'Signal rules', 'route' => route('tech.admin.system.signals.rules.index')],
                ['label' => 'Signal settings', 'route' => route('tech.admin.system.signals.settings.edit')],
            ],
        ],
        [
            'title' => 'Integrations',
            'icon' => 'bi-plug',
            'description' => 'External systems, API access, AI providers, and sync connections.',
            'links' => [
                ['label' => 'All integrations', 'route' => route('tech.admin.system.integrations.index')],
                ['label' => 'Nexum relationships', 'route' => route('tech.admin.system.relationships.index')],
                ['label' => 'N-able RMM', 'route' => route('tech.admin.system.integrations.nable_rmm.settings')],
                ['label' => 'Tactical RMM', 'route' => route('tech.admin.system.integrations.tactical_rmm.settings')],
                ['label' => 'BookStack', 'route' => route('tech.admin.system.integrations.book_stack.settings')],
                ['label' => 'Nextcloud', 'route' => route('tech.admin.nextcloud.connections.index')],
                ['label' => 'API management', 'route' => route('tech.admin.system.integrations.api.index')],
                ['label' => 'AI settings', 'route' => route('tech.admin.system.integrations.ai.index')],
            ],
        ],
        [
            'title' => 'Intake',
            'icon' => 'bi-inboxes',
            'description' => 'Configure public inquiry forms, review submissions, attachments, and Sales routing.',
            'links' => [
                ['label' => 'Forms and submissions', 'route' => route('tech.admin.system.intake.index')],
                ['label' => 'New form', 'route' => route('tech.admin.system.intake.forms.create')],
            ],
        ],
        [
            'title' => 'Booking',
            'icon' => 'bi-calendar-check',
            'description' => 'Configure bookable services, public slot requests, staff confirmation, and Calendar handoff.',
            'links' => [
                ['label' => 'Services and requests', 'route' => route('tech.admin.system.booking.index')],
                ['label' => 'New booking service', 'route' => route('tech.admin.system.booking.settings.create')],
            ],
        ],
        [
            'title' => 'Data Exchange',
            'icon' => 'bi-arrow-left-right',
            'description' => 'Reusable import/export profiles, run history, generated files, and safe data source registration.',
            'links' => [
                ['label' => 'Profiles and runs', 'route' => route('tech.admin.system.data-exchange.index')],
            ],
        ],
    ];
@endphp

@section('pageHeader')
    <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h1 class="h4 mb-0">Admin</h1>

        <!-- Application version and deferred GitHub status -->
        <div id="application-version-status"
             class="application-version-status d-flex flex-wrap align-items-center justify-content-end gap-2"
             data-status-url="{{ route('tech.admin.system.version-status') }}"
             aria-live="polite">
            <span class="badge text-bg-dark">v{{ $applicationVersionStatus['installed_version'] }}</span>

            @if($applicationVersionStatus['installed_commit_short'])
                <span class="badge text-bg-secondary"
                      title="Deployed commit {{ $applicationVersionStatus['installed_commit'] }}">
                    Commit {{ $applicationVersionStatus['installed_commit_short'] }}
                </span>
            @endif

            <span class="small text-muted d-inline-flex align-items-center gap-1" data-version-status-remote>
                <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                Checking GitHub
            </span>
        </div>
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

        .application-version-status {
            min-height: 1.5rem;
        }
    </style>

    <script>
        (() => {
            const container = document.getElementById('application-version-status');
            const remote = container?.querySelector('[data-version-status-remote]');

            if (!container || !remote) {
                return;
            }

            const badge = (text, className = 'text-bg-secondary') => {
                const element = document.createElement('span');
                element.className = `badge ${className}`;
                element.textContent = text;

                return element;
            };

            const releaseBadge = (status) => {
                const release = status.latest_release;

                if (status.release_status === 'update_available' && release?.version) {
                    let safeUrl = null;

                    if (release?.url) {
                        try {
                            const url = new URL(release.url);

                            if (url.protocol === 'https:' && url.hostname === 'github.com') {
                                safeUrl = url.toString();
                            }
                        } catch (error) {
                            // An invalid remote URL is rendered as non-navigable status text.
                        }
                    }

                    const element = document.createElement(safeUrl ? 'a' : 'span');
                    element.className = 'badge text-bg-warning text-dark text-decoration-none';
                    element.textContent = `New v${release.version}`;

                    if (safeUrl) {
                        element.href = safeUrl;
                        element.target = '_blank';
                        element.rel = 'noopener noreferrer';
                    }

                    return element;
                }

                if (status.release_status === 'current') {
                    return badge('Latest release', 'text-bg-success');
                }

                return badge('Release unknown');
            };

            const comparisonBadge = (status) => {
                const branch = status.update_branch || 'branch';

                switch (status.comparison_status) {
                    case 'current':
                        return badge(`${branch}: up to date`, 'text-bg-success');
                    case 'behind':
                        return badge(`${branch}: ${status.commits_behind ?? 0} commits behind`, 'text-bg-warning text-dark');
                    case 'ahead':
                        return badge(`${branch}: ${status.commits_ahead ?? 0} commits ahead`, 'text-bg-info text-dark');
                    case 'diverged':
                        return badge(`${branch}: ${status.commits_behind ?? 0} behind / ${status.commits_ahead ?? 0} ahead`, 'text-bg-warning text-dark');
                    case 'commit_unknown':
                        return badge('Commit unknown');
                    default:
                        return badge('Branch comparison unavailable');
                }
            };

            fetch(container.dataset.statusUrl, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                },
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`Status request failed with HTTP ${response.status}`);
                    }

                    return response.json();
                })
                .then((status) => {
                    const items = [releaseBadge(status), comparisonBadge(status)];

                    if (status.stale) {
                        const stale = badge('Cached status', 'text-bg-secondary');
                        stale.title = status.checked_at
                            ? `Last successful GitHub check: ${new Date(status.checked_at).toLocaleString()}`
                            : 'The latest successful GitHub check is being shown.';
                        items.push(stale);
                    } else if (!status.github_available) {
                        items.push(badge('GitHub unavailable', 'text-bg-secondary'));
                    }

                    remote.replaceChildren(...items);
                    remote.classList.remove('text-muted');
                })
                .catch(() => {
                    remote.replaceChildren(badge('GitHub unavailable', 'text-bg-secondary'));
                    remote.classList.remove('text-muted');
                });
        })();
    </script>
@endsection
