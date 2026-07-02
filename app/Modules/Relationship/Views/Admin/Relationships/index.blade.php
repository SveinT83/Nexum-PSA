@extends('layouts.default_tech')

@section('title', 'Nexum relationships')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center w-100">
        <div>
            <h1 class="h4 mb-0">Nexum relationships</h1>
            <p class="text-muted mb-0">Connected Nexum installations, routing policy, sync health, and audit state.</p>
        </div>
        <a href="{{ route('tech.admin.system.relationships.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            New relationship
        </a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Relationship summary -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-muted text-uppercase">Active</div>
                    <div class="fs-4 fw-semibold">{{ $stats['active'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-muted text-uppercase">Failing</div>
                    <div class="fs-4 fw-semibold">{{ $stats['failing'] }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Relationship list -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Direction</th>
                        <th>Linked record</th>
                        <th>Status</th>
                        <th>Health</th>
                        <th>Links</th>
                        <th>Events</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($relationships as $relationship)
                        <tr>
                            <td class="fw-semibold">{{ $relationship->name }}</td>
                            <td>{{ str_replace('_', ' ', ucfirst($relationship->direction)) }}</td>
                            <td>
                                @if($relationship->client)
                                    Client: {{ $relationship->client->name }}
                                @elseif($relationship->vendor)
                                    Vendor: {{ $relationship->vendor->name }}
                                @else
                                    <span class="text-muted">Not linked</span>
                                @endif
                            </td>
                            <td><span class="badge text-bg-light border">{{ ucfirst($relationship->status) }}</span></td>
                            <td><span class="badge text-bg-light border">{{ ucfirst($relationship->health_status) }}</span></td>
                            <td>{{ $relationship->sync_links_count }}</td>
                            <td>{{ $relationship->sync_events_count }}</td>
                            <td class="text-end">
                                <a href="{{ route('tech.admin.system.relationships.show', $relationship) }}" class="btn btn-sm btn-outline-secondary">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-muted text-center py-4">No Nexum relationships configured.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $relationships->links() }}
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection
