@extends('layouts.default_tech')

@section('title', 'Nextcloud Settings')

@php
    $syncPreview = $latestSyncLog?->context['preview'] ?? [];
    $syncSummary = $latestSyncLog?->context['summary'] ?? [];
    $hasPushCalendarMappings = $connection->calendarMappings
        ->where('is_active', true)
        ->whereIn('sync_direction', ['two_way', 'push_only'])
        ->isNotEmpty();
    $folderSegments = collect(explode('/', trim($folderPath, '/')))->filter()->values();
    $parentFolderPath = $folderSegments->isEmpty() ? '/' : '/'.$folderSegments->slice(0, -1)->implode('/');
    $parentFolderPath = $parentFolderPath === '' ? '/' : $parentFolderPath;
    $openFolderBrowser = request()->boolean('browse_folders');
    $isClientScoped = $connection->scope !== 'global';
@endphp

@section('pageHeader')
    <div class="col">
        <h1>{{ $connection->name }}</h1>
    </div>
    <div class="col-auto d-flex gap-2">
        <x-buttons.back url="{{ route('tech.admin.nextcloud.connections.index') }}" class="mb-0">Back</x-buttons.back>
        <form method="POST" action="{{ route('tech.admin.nextcloud.connections.check', $connection) }}">
            @csrf
            <button class="btn btn-sm btn-outline-secondary" type="submit">Check</button>
        </form>
        <form method="POST" action="{{ route('tech.admin.nextcloud.connections.sync', $connection) }}">
            @csrf
            <button class="btn btn-sm btn-primary" type="submit">Sync now</button>
        </form>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

@section('content')
    @if($hasPushCalendarMappings && ! $connection->canWrite())
        <div class="alert alert-warning">
            This connection is read-only. Nextcloud events can be pulled into Nexum, but Nexum events will not be pushed to Nextcloud until the connection mode is changed to Sync or Managed.
        </div>
    @endif

    @if($isClientScoped && ! $connection->client_site_id)
        <div class="alert alert-warning">
            Set a default import site in Server Details before importing or syncing Nextcloud users into this client.
        </div>
    @endif

    <!-- Sync overview -->
    <div class="row g-3 mb-3">
        @foreach(['users' => 'Users', 'groups' => 'Groups', 'calendars' => 'Calendars', 'files' => 'Root items', 'calendar_events_imported' => 'Events in', 'calendar_events_pushed' => 'Events out'] as $key => $label)
            <div class="col-md-2">
                <div class="card h-100">
                    <div class="card-body py-3">
                        <div class="small text-muted">{{ $label }}</div>
                        <div class="h4 mb-0">{{ $syncSummary[$key] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Server details -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Server Details</h2>
        </div>
        <div class="card-body">
            @include('nextcloud::Admin.connections.partials.edit-form', ['connection' => $connection, 'clients' => $clients, 'sites' => $sites])
        </div>
    </div>

    <!-- Folder mappings -->
    <div class="card mb-3">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <button class="btn btn-sm btn-link link-dark text-decoration-none p-0 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#nextcloudClientFoldersCollapse" aria-expanded="false" aria-controls="nextcloudClientFoldersCollapse">
                <i class="bi bi-chevron-right me-1" aria-hidden="true"></i>
                {{ $isClientScoped ? 'Documents Folder' : 'Client Folders' }}
            </button>
            <div class="d-flex align-items-center gap-2">
                @if($isClientScoped)
                    <span class="badge text-bg-light border">{{ $connection->documents_folder ? 'Mapped' : 'Not mapped' }}</span>
                @else
                    <span class="badge text-bg-light border">{{ $connection->folderMappings->count() }} client mappings</span>
                    <form method="POST" action="{{ route('tech.admin.nextcloud.connections.folders.auto_match', $connection) }}" class="js-nextcloud-auto-match-form">
                        @csrf
                        <button class="btn btn-sm btn-outline-primary" type="submit">
                            <i class="bi bi-magic" aria-hidden="true"></i>
                            Auto match
                        </button>
                    </form>
                @endif
            </div>
        </div>
        <div id="nextcloudClientFoldersCollapse" class="collapse">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12">
                    <div class="row g-2 mb-3">
                        @if(! $isClientScoped)
                            <div class="col-lg-5">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-muted">Client root folder</div>
                                    <div class="fw-semibold text-break">{{ $connection->root_folder ?: 'Not selected' }}</div>
                                </div>
                            </div>
                        @endif
                        <div class="{{ $isClientScoped ? 'col-lg-10' : 'col-lg-5' }}">
                            <div class="border rounded p-2 h-100">
                                <div class="small text-muted">Documents folder</div>
                                <div class="fw-semibold text-break">{{ $connection->documents_folder ?: 'Not selected' }}</div>
                            </div>
                        </div>
                        <div class="col-lg-2 d-grid">
                            <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#nextcloudFolderBrowserModal">
                                <i class="bi bi-folder2-open" aria-hidden="true"></i>
                                Browse
                            </button>
                        </div>
                    </div>

                    @if($folderBrowserError)
                        <div class="alert alert-warning py-2">{{ $folderBrowserError }}</div>
                    @endif

                    @if($isClientScoped)
                        <div class="text-muted small">Choose the customer-side folder Nexum may use for future documentation and reports for {{ $scopedClient?->name ?? 'this client' }}.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Nextcloud folder</th>
                                        <th>Client</th>
                                        <th>Type</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($clientFolderEntries as $entry)
                                        @php
                                            $mapping = $connection->folderMappings->firstWhere('remote_path', $entry['path']);
                                        @endphp
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">{{ $entry['name'] }}</div>
                                                <div class="small text-muted text-break">{{ $entry['path'] }}</div>
                                            </td>
                                            <td colspan="2">
                                                <form method="POST" action="{{ route('tech.admin.nextcloud.connections.folders.store', $connection) }}" class="row g-2 align-items-center">
                                                    @csrf
                                                    <input type="hidden" name="remote_path" value="{{ $entry['path'] }}">
                                                    <div class="col-md-7">
                                                        <select name="client_id" class="form-select form-select-sm" required>
                                                            <option value="">Do not map</option>
                                                            @foreach($clients as $client)
                                                                <option value="{{ $client->id }}" @selected($mapping?->mappable_id === $client->id)>{{ $client->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <select name="purpose" class="form-select form-select-sm">
                                                            <option value="client_files" @selected(($mapping?->purpose ?? 'client_files') === 'client_files')>Client folder</option>
                                                            <option value="client_documents" @selected($mapping?->purpose === 'client_documents')>Client documents</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2 text-end">
                                                        <button class="btn btn-sm btn-outline-primary" type="submit">Map</button>
                                                    </div>
                                                </form>
                                            </td>
                                            <td class="text-end">
                                                @if($mapping)
                                                    <form method="POST" action="{{ route('tech.admin.nextcloud.folder-mappings.destroy', $mapping) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button class="btn btn-sm btn-link text-danger" type="submit">Remove</button>
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-muted">Choose a client root folder to list client folders here.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        </div>
    </div>

    @if(! $isClientScoped)
        <!-- Auto match progress modal -->
        <div class="modal fade" id="nextcloudAutoMatchModal" tabindex="-1" aria-labelledby="nextcloudAutoMatchModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="nextcloudAutoMatchModalLabel">Auto matching client folders</h2>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex align-items-start gap-3">
                            <div class="spinner-border text-primary flex-shrink-0" role="status" aria-hidden="true"></div>
                            <div>
                                <div class="fw-semibold">Matching Nexum clients to Nextcloud folders</div>
                                <div class="text-muted small mb-3">This may take a moment if AI fallback is used.</div>
                                <ol class="small mb-0 ps-3">
                                    <li>Reading folders from the selected client root folder.</li>
                                    <li>Running direct name matching.</li>
                                    <li>Asking the default AI agent for remaining uncertain matches.</li>
                                    <li>Saving accepted mappings.</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Folder browser modal -->
    <div class="modal fade" id="nextcloudFolderBrowserModal" tabindex="-1" aria-labelledby="nextcloudFolderBrowserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="nextcloudFolderBrowserModalLabel">Browse Nextcloud folders</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                        <div>
                            <div class="small text-muted">Current folder</div>
                            <div class="fw-semibold text-break">{{ $folderPath }}</div>
                        </div>
                        @if($folderPath !== '/')
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.admin.nextcloud.connections.show', ['connection' => $connection, 'folder_path' => $parentFolderPath, 'browse_folders' => 1]) }}">
                                <i class="bi bi-arrow-up" aria-hidden="true"></i>
                                Up
                            </a>
                        @endif
                    </div>

                    <div class="d-flex gap-2 flex-wrap mb-3">
                        @if(! $isClientScoped)
                            <form method="POST" action="{{ route('tech.admin.nextcloud.connections.folders.update', $connection) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="folder_type" value="root">
                                <input type="hidden" name="remote_path" value="{{ $folderPath }}">
                                <button class="btn btn-sm btn-primary" type="submit">Set as client root folder</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('tech.admin.nextcloud.connections.folders.update', $connection) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="folder_type" value="documents">
                            <input type="hidden" name="remote_path" value="{{ $folderPath }}">
                            <button class="btn btn-sm {{ $isClientScoped ? 'btn-primary' : 'btn-outline-primary' }}" type="submit">Set as documents folder</button>
                        </form>
                    </div>

                    @if($folderBrowserError)
                        <div class="alert alert-warning py-2 mb-0">{{ $folderBrowserError }}</div>
                    @else
                        <div class="list-group list-group-flush border-top">
                            @forelse($folderEntries as $entry)
                                <a class="list-group-item list-group-item-action d-flex align-items-center justify-content-between px-0"
                                   href="{{ route('tech.admin.nextcloud.connections.show', ['connection' => $connection, 'folder_path' => $entry['path'], 'browse_folders' => 1]) }}">
                                    <span class="text-truncate">
                                        <i class="bi bi-folder me-1" aria-hidden="true"></i>
                                        {{ $entry['name'] }}
                                    </span>
                                    <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
                                </a>
                            @empty
                                <div class="text-muted small border-top pt-2">No subfolders found in this folder.</div>
                            @endforelse
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- User mappings -->
    <div class="card mb-3">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <button class="btn btn-sm btn-link link-dark text-decoration-none p-0 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#nextcloudUsersCollapse" aria-expanded="false" aria-controls="nextcloudUsersCollapse">
                <i class="bi bi-chevron-right me-1" aria-hidden="true"></i>
                Users
            </button>
            <div class="d-flex align-items-center gap-2">
                @if(($latestSyncLog?->context['summary']['users'] ?? 0) > count($syncPreview['users'] ?? []))
                    <span class="small text-muted">Showing {{ count($syncPreview['users'] ?? []) }} of {{ $latestSyncLog->context['summary']['users'] }}</span>
                @endif
                <span class="badge text-bg-light border">{{ $connection->userMappings->count() }} mapped</span>
            </div>
        </div>
        <div id="nextcloudUsersCollapse" class="collapse">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nextcloud user</th>
                        <th>{{ $isClientScoped ? 'Client user / import' : 'Nexum user' }}</th>
                        <th>{{ $isClientScoped ? 'Client role' : 'Identity' }}</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($syncPreview['users'] ?? [] as $remoteUser)
                        @php
                            $mapping = $connection->userMappings->firstWhere('remote_user_id', $remoteUser);
                            $emailCandidate = filter_var($remoteUser, FILTER_VALIDATE_EMAIL) ? $remoteUser : null;
                            $suggestedContactId = $emailCandidate ? $clientContacts->firstWhere('email', $emailCandidate)?->id : null;
                            $selectedClientContactId = $mapping?->identity_model_type === \App\Models\Clients\ClientUser::class
                                ? $mapping?->identity_model_id
                                : $suggestedContactId;
                            $remoteUserGroups = collect($syncPreview['group_members'] ?? [])
                                ->filter(fn ($members) => in_array($remoteUser, $members, true))
                                ->keys();
                            $suggestedClientRole = $connection->groupMappings
                                ->whereIn('remote_group_id', $remoteUserGroups)
                                ->first(fn ($groupMapping) => filled($groupMapping->client_role))
                                ?->client_role;
                            $selectedClientRole = $mapping?->metadata['client_role'] ?? $suggestedClientRole ?? 'contact';
                            $selectedClientMappingAction = $mapping?->metadata['mapping_action'] ?? ($selectedClientContactId ? 'map_existing' : ($suggestedClientRole ? 'import' : 'skip'));
                        @endphp
                        <tr data-nextcloud-user-row>
                            <td>
                                <div>{{ $remoteUser }}</div>
                                @if($isClientScoped && $remoteUserGroups->isNotEmpty())
                                    <div class="small text-muted">Groups: {{ $remoteUserGroups->implode(', ') }}</div>
                                @endif
                            </td>
                            <td colspan="2">
                                <form method="POST" action="{{ route('tech.admin.nextcloud.connections.users.store', $connection) }}" class="row g-2 align-items-center" data-nextcloud-user-mapping-form>
                                    @csrf
                                    <input type="hidden" name="remote_user_id" value="{{ $remoteUser }}">
                                    <input type="hidden" name="remote_username" value="{{ $remoteUser }}">
                                    @if($emailCandidate)
                                        <input type="hidden" name="remote_email" value="{{ $emailCandidate }}">
                                    @endif
                                    @if($isClientScoped)
                                        <div class="col-md-3">
                                            <select name="mapping_action" class="form-select form-select-sm" required>
                                                <option value="skip" @selected($selectedClientMappingAction === 'skip')>Do not import/map</option>
                                                <option value="map_existing" @selected($selectedClientMappingAction === 'map_existing')>Map existing</option>
                                                <option value="import" @selected($selectedClientMappingAction === 'import')>Import</option>
                                            </select>
                                        </div>
                                        <div class="col-md-5">
                                            <select name="client_user_id" class="form-select form-select-sm">
                                                <option value="">Import as new client contact</option>
                                                @foreach($clientContacts as $contact)
                                                    <option value="{{ $contact->id }}" @selected($selectedClientContactId === $contact->id)>{{ $contact->name }} · {{ $contact->email ?: 'No email' }} · {{ $contact->role ?: 'Contact' }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <select name="client_role" class="form-select form-select-sm" required>
                                                @foreach($clientRoleOptions as $value => $label)
                                                    <option value="{{ $value }}" @selected($selectedClientRole === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @else
                                        <div class="col-md-4">
                                            <select name="user_id" class="form-select form-select-sm">
                                                <option value="">Do not import/map</option>
                                                @foreach($users as $user)
                                                    <option value="{{ $user->id }}" @selected($mapping?->user_id === $user->id)>{{ $user->name }} · {{ $user->email }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <select name="client_user_id" class="form-select form-select-sm">
                                                <option value="">No client contact</option>
                                                @foreach($clientContacts as $contact)
                                                    <option value="{{ $contact->id }}" @selected($selectedClientContactId === $contact->id)>
                                                        {{ $contact->name }} · {{ $contact->email ?: 'No email' }} · {{ $contact->site?->client?->name ?: 'Client' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <select name="identity_type" class="form-select form-select-sm">
                                                @foreach(['technician' => 'Technician', 'client_contact' => 'Client contact', 'portal_user' => 'Portal user', 'external' => 'External'] as $value => $label)
                                                    <option value="{{ $value }}" @selected(($mapping?->identity_type ?? ($suggestedContactId ? 'client_contact' : 'technician')) === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endif
                                    <div class="col-md-2 text-end">
                                        <button class="btn btn-sm btn-outline-primary" type="submit">{{ $isClientScoped ? 'Save' : 'Map' }}</button>
                                        <div class="small mt-1" data-nextcloud-user-mapping-status></div>
                                    </div>
                                </form>
                            </td>
                            <td class="text-end">
                                @if($mapping)
                                    <form method="POST" action="{{ route('tech.admin.nextcloud.user-mappings.destroy', $mapping) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-link text-danger" type="submit">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">Run Sync now with users/groups enabled to preview Nextcloud users.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        </div>
    </div>

    <!-- Group mappings -->
    <div class="card mb-3">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <button class="btn btn-sm btn-link link-dark text-decoration-none p-0 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#nextcloudGroupsCollapse" aria-expanded="false" aria-controls="nextcloudGroupsCollapse">
                <i class="bi bi-chevron-right me-1" aria-hidden="true"></i>
                Groups
            </button>
            <span class="badge text-bg-light border">{{ $connection->groupMappings->count() }} mapped</span>
        </div>
        <div id="nextcloudGroupsCollapse" class="collapse">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nextcloud group</th>
                        <th>{{ $isClientScoped ? 'Client role' : 'Nexum role' }}</th>
                        @if(! $isClientScoped)
                            <th>Client</th>
                        @endif
                        <th>Sync mode</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($syncPreview['groups'] ?? [] as $remoteGroup)
                        @php
                            $mapping = $connection->groupMappings->firstWhere('remote_group_id', $remoteGroup);
                        @endphp
                        <tr>
                            <td>{{ $remoteGroup }}</td>
                            <td colspan="{{ $isClientScoped ? 2 : 3 }}">
                                <form method="POST" action="{{ route('tech.admin.nextcloud.connections.groups.store', $connection) }}" class="row g-2 align-items-center">
                                    @csrf
                                    <input type="hidden" name="remote_group_id" value="{{ $remoteGroup }}">
                                    <input type="hidden" name="remote_group_name" value="{{ $remoteGroup }}">
                                    @if($isClientScoped)
                                        <div class="col-md-5">
                                            <select name="client_role" class="form-select form-select-sm" required>
                                                <option value="">Do not map</option>
                                                @foreach($clientRoleOptions as $value => $label)
                                                    <option value="{{ $value }}" @selected($mapping?->client_role === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @else
                                        <div class="col-md-4">
                                            <select name="role_id" class="form-select form-select-sm" required>
                                                <option value="">Do not map</option>
                                                @foreach($roles as $role)
                                                    <option value="{{ $role->id }}" @selected($mapping?->role_id === $role->id)>{{ $role->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <select name="client_id" class="form-select form-select-sm">
                                                <option value="">No client scope</option>
                                                @foreach($clients as $client)
                                                    <option value="{{ $client->id }}" @selected($mapping?->client_id === $client->id)>{{ $client->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endif
                                    <div class="{{ $isClientScoped ? 'col-md-5' : 'col-md-3' }}">
                                        <select name="sync_mode" class="form-select form-select-sm">
                                            @foreach(['preview_only' => 'Preview only', 'nextcloud_to_nexum' => 'Nextcloud group grants Nexum role', 'nexum_to_nextcloud' => 'Nexum role manages Nextcloud group'] as $value => $label)
                                                <option value="{{ $value }}" @selected(($mapping?->sync_mode ?? 'preview_only') === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="{{ $isClientScoped ? 'col-md-2' : 'col-md-1' }} text-end">
                                        <button class="btn btn-sm btn-outline-primary" type="submit">{{ $isClientScoped ? 'Save' : 'Map' }}</button>
                                    </div>
                                </form>
                            </td>
                            <td class="text-end">
                                @if($mapping)
                                    <form method="POST" action="{{ route('tech.admin.nextcloud.group-mappings.destroy', $mapping) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-link text-danger" type="submit">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $isClientScoped ? 4 : 5 }}" class="text-muted">Run Sync now with users/groups enabled to preview Nextcloud groups.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        </div>
    </div>

    <!-- Calendar mappings -->
    <div class="card">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <button class="btn btn-sm btn-link link-dark text-decoration-none p-0 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#nextcloudCalendarsCollapse" aria-expanded="false" aria-controls="nextcloudCalendarsCollapse">
                <i class="bi bi-chevron-right me-1" aria-hidden="true"></i>
                Calendars
            </button>
            <span class="badge text-bg-light border">{{ $connection->calendarMappings->count() }} mapped</span>
        </div>
        <div id="nextcloudCalendarsCollapse" class="collapse">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nextcloud calendar</th>
                        <th>Suggested user</th>
                        <th>Nexum calendar</th>
                        <th>Sync</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($syncPreview['calendars'] ?? [] as $remoteCalendar)
                        @php
                            $remoteId = $remoteCalendar['href'] ?? $remoteCalendar['display_name'];
                            $mapping = $connection->calendarMappings->firstWhere('remote_calendar_id', $remoteId);
                            $suggestedUserId = $connection->userMappings->firstWhere('remote_user_id', $remoteCalendar['remote_owner'] ?? null)?->user_id;
                        @endphp
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $remoteCalendar['display_name'] ?? $remoteId }}</div>
                                <div class="small text-muted">{{ $remoteCalendar['remote_owner'] ?? 'Unknown owner' }}</div>
                            </td>
                            <td colspan="3">
                                <form method="POST" action="{{ route('tech.admin.nextcloud.connections.calendars.store', $connection) }}" class="row g-2 align-items-center">
                                    @csrf
                                    <input type="hidden" name="remote_calendar_id" value="{{ $remoteId }}">
                                    <input type="hidden" name="remote_display_name" value="{{ $remoteCalendar['display_name'] ?? $remoteId }}">
                                    <div class="col-md-4">
                                        <select name="user_id" class="form-select form-select-sm">
                                            <option value="">No user owner</option>
                                            @foreach($users as $user)
                                                <option value="{{ $user->id }}" @selected(($mapping?->user_id ?? $suggestedUserId) === $user->id)>{{ $user->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="calendar_id" class="form-select form-select-sm">
                                            <option value="">Import as external later</option>
                                            @foreach($calendars as $calendar)
                                                <option value="{{ $calendar->id }}" @selected($mapping?->calendar_id === $calendar->id)>{{ $calendar->name }} · {{ $calendar->type }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select name="sync_direction" class="form-select form-select-sm">
                                            @foreach(['two_way' => 'Two way', 'pull_only' => 'Pull only', 'push_only' => 'Push only'] as $value => $label)
                                                <option value="{{ $value }}" @selected(($mapping?->sync_direction ?? 'two_way') === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-2 text-end">
                                        <button class="btn btn-sm btn-outline-primary" type="submit">Map</button>
                                    </div>
                                </form>
                            </td>
                            <td class="text-end">
                                @if($mapping)
                                    <form method="POST" action="{{ route('tech.admin.nextcloud.calendar-mappings.destroy', $mapping) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-link text-danger" type="submit">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">Enable calendar sync and run Sync now to preview Nextcloud calendars.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        </div>
    </div>

    <!-- Talk Bot Configuration -->
    <div class="card mb-3">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <button class="btn btn-sm btn-link link-dark text-decoration-none p-0 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#talkBotConfigCollapse" aria-expanded="false" aria-controls="talkBotConfigCollapse">
                <i class="bi bi-chevron-right me-1" aria-hidden="true"></i>
                Talk Bot Configuration
            </button>
            <div class="d-flex align-items-center gap-2">
                @if($connection->hasTalkBot())
                    <span class="badge text-bg-success"><i class="bi bi-robot me-1"></i>Bot configured</span>
                @else
                    <span class="badge text-bg-secondary">No bot</span>
                @endif
            </div>
        </div>
        <div id="talkBotConfigCollapse" class="collapse">
            <div class="card-body">
                <div class="alert alert-info py-2 small mb-3">
                    <strong>Talk Bot API</strong> enables signed, rich-message notifications to Nextcloud Talk conversations.
                    Requires NC 27.1+ / Talk 17.1+ with the <code>bots-v1</code> capability.
                    Install a bot on the Nextcloud server first:
                    <code>./occ talk:bot:install "Nexum Bot" &lt;secret&gt; &lt;webhook-url&gt; &lt;nextcloud-url&gt;</code>
                </div>

                <form method="POST" action="{{ route('tech.admin.nextcloud.connections.update', $connection) }}">
                    @csrf
                    @method('PATCH')

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="talk_bot_id">Bot ID</label>
                            <input type="number" id="talk_bot_id" name="talk_bot_id"
                                class="form-control" placeholder="e.g. 1"
                                value="{{ old('talk_bot_id', $connection->talk_bot_id) }}">
                            <div class="form-text">The numeric ID shown by <code>./occ talk:bot:list</code></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="talk_bot_secret">Bot Shared Secret</label>
                            <input type="password" id="talk_bot_secret" name="talk_bot_secret"
                                class="form-control" placeholder="Leave blank to keep current secret"
                                value=""
                                autocomplete="new-password">
                            <div class="form-text">HMAC-SHA256 shared secret from <code>talk:bot:install</code>. Leave blank to keep existing.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="talk_default_conversation_token">Default Conversation Token</label>
                            <input type="text" id="talk_default_conversation_token" name="talk_default_conversation_token"
                                class="form-control" placeholder="e.g. n3xtc10ud"
                                value="{{ old('talk_default_conversation_token', $connection->talk_default_conversation_token) }}">
                            <div class="form-text">The Talk conversation token where notifications are sent by default</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Bot Features</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="talk_bot_features[]" value="reaction" id="talkBotFeatureReaction"
                                    @checked(in_array('reaction', $connection->talk_bot_features ?? []))>
                                <label class="form-check-label" for="talkBotFeatureReaction">Reaction support</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="talk_bot_features[]" value="no-setup" id="talkBotFeatureNoSetup"
                                    @checked(in_array('no-setup', $connection->talk_bot_features ?? []))>
                                <label class="form-check-label" for="talkBotFeatureNoSetup">No-setup (one-way bot)</label>
                            </div>
                            <div class="form-text">Enable features matching your Talk server's bot configuration</div>
                        </div>
                    </div>

                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Save Talk Bot Settings</button>
                        @if($connection->hasTalkBot())
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="testTalkBotBtn">Test Bot Message</button>
                        @endif
                    </div>
                </form>
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

                clientField?.classList.toggle('d-none', scope === 'global');
                siteField?.classList.toggle('d-none', scope === 'global');

                if (clientSelect) {
                    clientSelect.disabled = scope === 'global';
                    if (scope === 'global') clientSelect.value = '';
                }

                if (siteSelect) {
                    siteSelect.disabled = scope === 'global';
                    if (scope === 'global') siteSelect.value = '';
                }

                if (mode && scope !== 'global') mode.value = 'read_only';
                modeHelp?.classList.toggle('d-none', scope === 'global');
            }

            document.querySelectorAll('.js-nextcloud-connection-form').forEach(function (form) {
                syncScopeFields(form);
                form.querySelector('.js-nextcloud-scope')?.addEventListener('change', function () {
                    syncScopeFields(form);
                });
            });

            document.querySelectorAll('[data-bs-toggle="collapse"][data-bs-target]').forEach(function (button) {
                const target = document.querySelector(button.getAttribute('data-bs-target'));
                const icon = button.querySelector('.bi');

                if (! target || ! icon) {
                    return;
                }

                target.addEventListener('show.bs.collapse', function () {
                    icon.classList.remove('bi-chevron-right');
                    icon.classList.add('bi-chevron-down');
                    button.setAttribute('aria-expanded', 'true');
                });

                target.addEventListener('hide.bs.collapse', function () {
                    icon.classList.remove('bi-chevron-down');
                    icon.classList.add('bi-chevron-right');
                    button.setAttribute('aria-expanded', 'false');
                });
            });

            document.querySelectorAll('.js-nextcloud-auto-match-form').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    const modalElement = document.getElementById('nextcloudAutoMatchModal');
                    const button = form.querySelector('button[type="submit"]');

                    if (! modalElement || ! window.bootstrap?.Modal || form.dataset.submitting === '1') {
                        return;
                    }

                    event.preventDefault();
                    form.dataset.submitting = '1';
                    button?.setAttribute('disabled', 'disabled');
                    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();

                    window.setTimeout(function () {
                        form.submit();
                    }, 150);
                });
            });

            @if($openFolderBrowser)
                const folderBrowserModal = document.getElementById('nextcloudFolderBrowserModal');
                if (folderBrowserModal && window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(folderBrowserModal).show();
                }
            @endif

            document.querySelectorAll('[data-nextcloud-user-mapping-form]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();

                    const button = form.querySelector('button[type="submit"]');
                    const status = form.querySelector('[data-nextcloud-user-mapping-status]');
                    const originalText = button ? button.textContent : '';
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || form.querySelector('input[name="_token"]')?.value;

                    if (button) {
                        button.disabled = true;
                        button.textContent = 'Saving...';
                    }

                    if (status) {
                        status.className = 'small mt-1 text-muted';
                        status.textContent = 'Saving';
                    }

                    fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: new FormData(form),
                    })
                        .then(async function (response) {
                            const data = await response.json().catch(function () {
                                return {};
                            });

                            if (! response.ok || data.success === false) {
                                const message = data.message || Object.values(data.errors || {})[0]?.[0] || 'Could not save mapping.';
                                throw new Error(message);
                            }

                            if (status) {
                                status.className = 'small mt-1 text-success';
                                status.textContent = data.message || 'Saved';
                            }
                        })
                        .catch(function (error) {
                            if (status) {
                                status.className = 'small mt-1 text-danger';
                                status.textContent = error.message || 'Could not save';
                            }
                        })
                        .finally(function () {
                            if (button) {
                                button.disabled = false;
                                button.textContent = originalText;
                            }
                        });
                });
            });

            // Talk Bot test message
            const testTalkBotBtn = document.getElementById('testTalkBotBtn');
            if (testTalkBotBtn) {
                testTalkBotBtn.addEventListener('click', function () {
                    testTalkBotBtn.disabled = true;
                    testTalkBotBtn.textContent = 'Sending…';
                    fetch('{{ route('tech.admin.nextcloud.connections.test-talk-bot', $connection) }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: 'action=test_talk_bot',
                    })
                    .then(r => r.json())
                    .then(data => {
                        const msg = data.success ? '✅ Test message sent!' : ('❌ ' + (data.error || 'Unknown error'));
                        alert(msg);
                    })
                    .catch(() => alert('❌ Request failed'))
                    .finally(() => {
                        testTalkBotBtn.disabled = false;
                        testTalkBotBtn.textContent = 'Test Bot Message';
                    });
                });
            }
        });
    </script>
@endsection
