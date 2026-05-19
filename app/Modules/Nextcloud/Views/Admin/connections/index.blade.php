@extends('layouts.default_tech')

@section('title', 'Nextcloud')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-1">Nextcloud</h1>
        <div class="text-muted small">Connections, credentials, sync settings, and future mappings.</div>
    </div>
    <div class="col-auto">
        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#nextcloudCreateModal">
            <i class="bi bi-cloud-plus" aria-hidden="true"></i>
            Add Connection
        </button>
    </div>
@endsection

@section('content')
    @foreach(['success' => 'success', 'warning' => 'warning', 'info' => 'info'] as $key => $type)
        @if(session($key))
            <div class="alert alert-{{ $type }}">{{ session($key) }}</div>
        @endif
    @endforeach

    <!-- Connections inventory -->
    <div class="card">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <h2 class="h6 mb-0">Connections</h2>
            <span class="badge text-bg-light border">{{ $connections->count() }} configured</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Scope</th>
                        <th>Mode</th>
                        <th>Target</th>
                        <th>Status</th>
                        <th>Mappings</th>
                        <th>Sync</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($connections as $connection)
                        @php
                            $latestSyncLog = $connection->syncLogs->first();
                        @endphp
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $connection->name }}</div>
                                <div class="small text-muted">{{ $connection->base_url }}</div>
                                @if($connection->admin_url)
                                    <a class="small" href="{{ $connection->admin_url }}" target="_blank" rel="noopener">Open admin</a>
                                @endif
                            </td>
                            <td>
                                <span class="badge text-bg-light border">{{ str_replace('_', ' ', ucfirst($connection->scope)) }}</span>
                                @if($connection->is_default)
                                    <span class="badge text-bg-primary">Default</span>
                                @endif
                            </td>
                            <td>{{ str_replace('_', ' ', ucfirst($connection->mode)) }}</td>
                            <td class="small">
                                @if($connection->scope === 'global')
                                    Internal/global
                                @elseif($connection->scope === 'site')
                                    {{ $connection->site?->name ?? 'No site selected' }}
                                    <div class="text-muted">{{ $connection->client?->name }}</div>
                                @else
                                    {{ $connection->client?->name ?? 'No client selected' }}
                                @endif
                            </td>
                            <td>
                                @php
                                    $statusClass = match($connection->health_status) {
                                        'healthy' => 'text-bg-success',
                                        'error' => 'text-bg-danger',
                                        default => 'text-bg-secondary',
                                    };
                                @endphp
                                <span class="badge {{ $statusClass }}">{{ ucfirst($connection->health_status) }}</span>
                                @if($connection->last_error)
                                    <div class="small text-danger text-truncate" style="max-width: 16rem;">{{ $connection->last_error }}</div>
                                @endif
                            </td>
                            <td class="small">
                                Folders {{ $connection->folder_mappings_count }} · Calendars {{ $connection->calendar_mappings_count }}<br>
                                Users {{ $connection->user_mappings_count }} · Groups {{ $connection->group_mappings_count }} · Conflicts {{ $connection->conflicts_count }}
                            </td>
                            <td class="small">
                                Every {{ $connection->sync_interval_minutes }} min<br>
                                <span class="text-muted">Last: {{ $connection->last_successful_sync_at?->diffForHumans() ?? 'never' }}</span>
                                @if($latestSyncLog)
                                    <div>
                                        <span class="badge text-bg-light border">{{ ucfirst($latestSyncLog->status) }}</span>
                                        <span class="text-muted">{{ $latestSyncLog->finished_at?->diffForHumans() ?? $latestSyncLog->created_at->diffForHumans() }}</span>
                                    </div>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-1 flex-wrap">
                                    <form method="POST" action="{{ route('tech.admin.nextcloud.connections.check', $connection) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Check</button>
                                    </form>
                                    <form method="POST" action="{{ route('tech.admin.nextcloud.connections.sync', $connection) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-primary" type="submit">Sync now</button>
                                    </form>
                                    <form method="POST" action="{{ route('tech.admin.nextcloud.connections.destroy', $connection) }}" onsubmit="return confirm('Delete this Nextcloud connection?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                    </form>
                                    <a class="btn btn-sm btn-primary" href="{{ route('tech.admin.nextcloud.connections.show', $connection) }}">Settings</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-muted">No Nextcloud connections configured.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Connection creation modal -->
    <div class="modal fade" id="nextcloudCreateModal" tabindex="-1" aria-labelledby="nextcloudCreateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="nextcloudCreateModalLabel">Add Nextcloud Connection</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('nextcloud::Admin.connections.partials.create-form', ['clients' => $clients, 'sites' => $sites])
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            function syncScopeFields(form) {
                const scope = form.querySelector('.js-nextcloud-scope')?.value || 'global';
                const mode = form.querySelector('.js-nextcloud-mode');
                const clientField = form.querySelector('.js-nextcloud-client-field');
                const siteField = form.querySelector('.js-nextcloud-site-field');
                const clientSelect = clientField?.querySelector('select');
                const siteSelect = siteField?.querySelector('select');
                const modeHelp = form.querySelector('.js-nextcloud-mode-help');

                if (clientField) {
                    clientField.classList.toggle('d-none', scope === 'global');
                }

                if (siteField) {
                    siteField.classList.toggle('d-none', scope === 'global');
                }

                if (clientSelect) {
                    clientSelect.disabled = scope === 'global';
                    if (scope === 'global') {
                        clientSelect.value = '';
                    }
                }

                if (siteSelect) {
                    siteSelect.disabled = scope === 'global';
                    if (scope === 'global') {
                        siteSelect.value = '';
                    }
                }

                if (mode && scope !== 'global') {
                    mode.value = 'read_only';
                }

                if (modeHelp) {
                    modeHelp.classList.toggle('d-none', scope === 'global');
                }
            }

            document.querySelectorAll('.js-nextcloud-connection-form').forEach(function (form) {
                syncScopeFields(form);
                form.querySelector('.js-nextcloud-scope')?.addEventListener('change', function () {
                    syncScopeFields(form);
                });
            });
        });
    </script>
@endsection
