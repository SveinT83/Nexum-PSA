@extends('layouts.default_tech')

@section('title', 'Admin')

@php
    $adminSections = app(\App\Modules\System\Queries\SystemAdminNavigation::class)->all();
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
