@extends('layouts.default_tech')

@section('title', 'Supplier Order Profiles')

@section('sidebar')
    <x-nav.admin-menu group="storage" />
@endsection

@section('pageHeader')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">Supplier Order Profiles</h1>
            <div class="small text-muted">Versioned deterministic extraction definitions and protected fixture gates.</div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('tech.admin.settings.storage.supplier-order-profiles.import') }}" class="btn btn-sm btn-outline-secondary">Import JSON</a>
            <x-buttons.addlink :url="route('tech.admin.settings.storage.supplier-order-profiles.create')" class="mb-0">New Profile</x-buttons.addlink>
        </div>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        {{-- Filters are intentionally limited to stable profile metadata. --}}
        <form method="GET" action="{{ route('tech.admin.settings.storage.supplier-order-profiles.index') }}" class="card mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-4">
                        <label for="profile_search" class="form-label small text-muted mb-1">Search</label>
                        <input type="search" id="profile_search" name="q" class="form-control form-control-sm"
                               value="{{ request('q') }}" placeholder="Name, slug, description, or supplier">
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="state" class="form-label small text-muted mb-1">Lifecycle state</label>
                        <select id="state" name="state" class="form-select form-select-sm">
                            <option value="">All states</option>
                            @foreach(['draft', 'shadow', 'active', 'degraded', 'paused', 'retired'] as $state)
                                <option value="{{ $state }}" @selected(request('state') === $state)>{{ str($state)->title() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="vendor_id" class="form-label small text-muted mb-1">Supplier</label>
                        <select id="vendor_id" name="vendor_id" class="form-select form-select-sm">
                            <option value="">All suppliers</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" @selected((int) request('vendor_id') === (int) $vendor->id)>{{ $vendor->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="sort" class="form-label small text-muted mb-1">Sort</label>
                        <select id="sort" name="sort" class="form-select form-select-sm">
                            @foreach(['updated' => 'Updated', 'name' => 'Name', 'supplier' => 'Supplier', 'state' => 'State', 'health' => 'Health', 'priority' => 'Priority'] as $value => $label)
                                <option value="{{ $value }}" @selected(request('sort', 'updated') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-1">
                        <label for="direction" class="form-label small text-muted mb-1">Direction</label>
                        <select id="direction" name="direction" class="form-select form-select-sm">
                            <option value="desc" @selected(request('direction', 'desc') === 'desc')>Desc</option>
                            <option value="asc" @selected(request('direction') === 'asc')>Asc</option>
                        </select>
                    </div>
                    <div class="col-lg-1 d-grid">
                        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                    </div>
                </div>
            </div>
        </form>

        {{-- Profile rows expose lifecycle and test-corpus health without mutating definitions. --}}
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <h2 class="h6 mb-0">Profiles</h2>
                <span class="badge text-bg-light border">{{ $profiles->total() }} total</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Profile</th>
                        <th>Supplier</th>
                        <th>State</th>
                        <th>Health</th>
                        <th class="text-end">Priority</th>
                        <th>Active version</th>
                        <th class="text-end">Versions</th>
                        <th class="text-end">Fixtures</th>
                        <th>Updated</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($profiles as $profile)
                        <tr>
                            <td>
                                <a href="{{ route('tech.admin.settings.storage.supplier-order-profiles.show', $profile) }}" class="fw-semibold text-decoration-none">
                                    {{ $profile->name }}
                                </a>
                                <div class="small text-muted">{{ $profile->slug }}</div>
                            </td>
                            <td>{{ $profile->vendor?->name ?: 'Generic / unmatched' }}</td>
                            <td>
                                <span class="badge {{ $profile->lifecycle_state === 'active' ? 'text-bg-success' : ($profile->lifecycle_state === 'degraded' ? 'text-bg-warning' : 'text-bg-light border') }}">
                                    {{ str($profile->lifecycle_state)->title() }}
                                </span>
                            </td>
                            <td>{{ str($profile->health_state ?: 'unknown')->replace('_', ' ')->title() }}</td>
                            <td class="text-end">{{ $profile->priority }}</td>
                            <td>{{ $profile->activeVersion?->version_number ? 'v'.$profile->activeVersion->version_number : '-' }}</td>
                            <td class="text-end">{{ $profile->versions_count }}</td>
                            <td class="text-end">{{ $profile->fixtures_count }}</td>
                            <td>{{ $profile->updated_at?->format('d.m.Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-5">No supplier profiles have been created.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($profiles->hasPages())
                <div class="card-footer">{{ $profiles->links() }}</div>
            @endif
        </div>
    </div>
@endsection
