@extends('layouts.default_tech')

@section('title', 'Data Exchange')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">Data Exchange</h1>
        <div class="d-flex gap-2">
            @can('data_exchange.manage')
                <a href="{{ route('tech.admin.system.data-exchange.profiles.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    Create profile
                </a>
            @endcan
            <x-buttons.back :url="route('tech.admin.index')" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Data Exchange Summary -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-muted">Profiles</div>
                    <div class="h4 mb-0">{{ $stats['profiles'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-muted">Runs</div>
                    <div class="h4 mb-0">{{ $stats['runs'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-muted">Files</div>
                    <div class="h4 mb-0">{{ $stats['files'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-muted">Schedules</div>
                    <div class="h4 mb-0">{{ $stats['schedules'] }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Profile List -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Profiles">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Direction</th>
                        <th>Format</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($profiles as $profile)
                        @php($latestFile = $profile->files->first())
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $profile->name }}</div>
                                <div class="small text-muted">{{ $profile->key }}</div>
                            </td>
                            <td>{{ ucfirst($profile->direction) }}</td>
                            <td>{{ $profile->format ? strtoupper($profile->format) : 'Not set' }}</td>
                            <td><span class="badge text-bg-light border">{{ $profile->status }}</span></td>
                            <td class="small text-muted">{{ $profile->updated_at?->diffForHumans() }}</td>
                            <td class="text-end">
                                <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                    @can('data_exchange.manage')
                                        <a href="{{ route('tech.admin.system.data-exchange.profiles.edit', $profile) }}" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                            Edit
                                        </a>
                                    @endcan
                                    @if($profile->direction === 'export')
                                        @can('data_exchange.run')
                                            <form method="POST" action="{{ route('tech.admin.system.data-exchange.runs.store', $profile) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-play-fill" aria-hidden="true"></i>
                                                    Run
                                                </button>
                                            </form>
                                        @endcan
                                    @endif
                                    @if($latestFile)
                                        @can('data_exchange.download')
                                            <a href="{{ route('tech.admin.system.data-exchange.files.download', $latestFile) }}" class="btn btn-sm btn-outline-success">
                                                <i class="bi bi-download" aria-hidden="true"></i>
                                                Latest
                                            </a>
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No Data Exchange profiles found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($profiles->hasPages())
            <div class="card-footer">
                {{ $profiles->links() }}
            </div>
        @endif
    </x-card.default>

    <!-- ------------------------------------------------- -->
    <!-- Import Dry Run -->
    <!-- ------------------------------------------------- -->
    @can('data_exchange.import')
        <div class="row g-3 mt-1">
            <div class="col-lg-6">
                <x-card.default title="Import Dry-Run">
                    <form method="POST" action="{{ route('tech.admin.system.data-exchange.imports.dry-run') }}" enctype="multipart/form-data" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-md-6">
                            <label class="form-label small" for="data_exchange_import_profile">Profile</label>
                            <select id="data_exchange_import_profile" name="profile_id" class="form-select form-select-sm" required>
                                @foreach($importProfiles as $profile)
                                    <option value="{{ $profile->id }}">{{ $profile->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small" for="data_exchange_import_file">File</label>
                            <input id="data_exchange_import_file" name="file" type="file" class="form-control form-control-sm" accept=".csv,.json,.xlsx" required>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                Preview import
                            </button>
                        </div>
                    </form>
                </x-card.default>
            </div>
            <div class="col-lg-6">
                <x-card.default title="Recent Import Previews">
                    <div class="list-group list-group-flush">
                        @forelse($importPreviews as $preview)
                            <a href="{{ route('tech.admin.system.data-exchange.imports.show', $preview) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <span>
                                    <span class="fw-semibold">{{ $preview->profile?->name ?? 'Deleted profile' }}</span>
                                    <span class="text-muted small d-block">{{ $preview->original_filename }} · {{ $preview->row_count }} rows</span>
                                </span>
                                <span class="badge text-bg-light border">{{ $preview->status }}</span>
                            </a>
                        @empty
                            <div class="text-muted small">No import previews yet.</div>
                        @endforelse
                    </div>
                </x-card.default>
            </div>
        </div>
    @endcan

    <!-- ------------------------------------------------- -->
    <!-- Schedules And Delivery Targets -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3 mt-1">
        @can('data_exchange.schedule')
            <div class="col-lg-6">
                <x-card.default title="Schedules">
                    <form method="POST" action="{{ route('tech.admin.system.data-exchange.schedules.store') }}" class="row g-2 align-items-end mb-3">
                        @csrf
                        <div class="col-md-6">
                            <label class="form-label small">Profile</label>
                            <select name="profile_id" class="form-select form-select-sm" required>
                                @foreach($allProfiles as $profile)
                                    <option value="{{ $profile->id }}">{{ $profile->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Frequency</label>
                            <select name="frequency" class="form-select form-select-sm">
                                <option value="daily">Daily</option>
                                <option value="hourly">Hourly</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Run time</label>
                            <input name="run_time" type="time" class="form-control form-control-sm" value="02:00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Delivery target</label>
                            <select name="delivery_target_id" class="form-select form-select-sm">
                                <option value="">Manual/API only</option>
                                @foreach($deliveryTargets as $target)
                                    <option value="{{ $target->id }}">{{ $target->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="hidden" name="direction" value="export">
                            <label class="form-check small mt-4">
                                <input class="form-check-input" type="checkbox" name="active" value="1">
                                Active
                            </label>
                        </div>
                        <div class="col-md-3 text-end">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Profile</th>
                                    <th>Frequency</th>
                                    <th>Next</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($schedules as $schedule)
                                    <tr>
                                        <td>{{ $schedule->profile?->name ?? 'Deleted profile' }}</td>
                                        <td>{{ ucfirst($schedule->frequency) }}</td>
                                        <td class="small text-muted">{{ $schedule->next_run_at?->format('Y-m-d H:i') ?? '-' }}</td>
                                        <td><span class="badge text-bg-light border">{{ $schedule->active ? 'Active' : 'Paused' }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted small">No schedules yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-card.default>
            </div>
        @endcan

        @can('data_exchange.delivery')
            <div class="col-lg-6">
                <x-card.default title="Delivery Targets">
                    <form method="POST" action="{{ route('tech.admin.system.data-exchange.delivery-targets.store') }}" class="row g-2 align-items-end mb-3">
                        @csrf
                        <div class="col-md-6">
                            <label class="form-label small">Name</label>
                            <input name="name" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Type</label>
                            <select name="type" class="form-select form-select-sm">
                                <option value="local">Local disk</option>
                                <option value="ftp">FTP disk</option>
                                <option value="sftp">SFTP disk</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Direction</label>
                            <select name="direction" class="form-select form-select-sm">
                                <option value="export">Export</option>
                                <option value="import">Import</option>
                                <option value="both">Both</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Filesystem disk</label>
                            <input name="filesystem_disk" class="form-control form-control-sm" placeholder="sftp-accounting">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Path</label>
                            <input name="remote_path" class="form-control form-control-sm" placeholder="exports/orders">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Credential ref</label>
                            <input name="credential_reference" class="form-control form-control-sm" placeholder="integration:123">
                        </div>
                        <div class="col-md-8">
                            <label class="form-check small">
                                <input class="form-check-input" type="checkbox" name="active" value="1" checked>
                                Active
                            </label>
                        </div>
                        <div class="col-md-4 text-end">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Disk</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($deliveryTargets as $target)
                                    <tr>
                                        <td>{{ $target->name }}</td>
                                        <td>{{ strtoupper($target->type) }}</td>
                                        <td class="small text-muted">{{ $target->filesystem_disk ?: '-' }}</td>
                                        <td><span class="badge text-bg-light border">{{ $target->active ? 'Active' : 'Paused' }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted small">No delivery targets yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-card.default>
            </div>
        @endcan
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="data-exchange" />
@endsection

@section('rightbar')
    <x-card.default title="Registered Sources">
        @if($registeredSources->isEmpty())
            <p class="small text-muted mb-0">No data sources are registered.</p>
        @else
            <div class="d-grid gap-2">
                @foreach($registeredSources as $source)
                    <div class="border rounded p-2">
                        <div class="fw-semibold small">{{ $source->label }}</div>
                        <div class="text-muted small">{{ $source->module }} · {{ count($source->exportableFields()) }} fields</div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-card.default>
@endsection
