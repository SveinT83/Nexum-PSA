@extends('layouts.default_tech')

@section('title', 'Web Push Devices')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">Web Push Devices</h1>
    </div>
    <div class="col-auto">
        <x-buttons.back url="{{ route('tech.admin.notification-channels.index') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    {{-- Channel readiness --}}
    <div class="alert {{ $readiness['ready'] ? 'alert-success' : 'alert-warning' }} py-2">
        <strong>{{ $readiness['ready'] ? 'Web Push ready' : 'Web Push unavailable' }}.</strong>
        {{ $readiness['message'] }}
    </div>

    {{-- Device inventory --}}
    <div class="card shadow-sm">
        <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0">Internal-user devices</h2>
            <span class="badge text-bg-secondary">{{ $devices->total() }} devices</span>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('tech.admin.notification-channels.web-push.devices.index') }}" class="row g-2 align-items-end mb-3">
                <div class="col-md-8">
                    <label for="webPushDeviceSearch" class="form-label">User name or email</label>
                    <input
                        id="webPushDeviceSearch"
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        class="form-control"
                        placeholder="Search internal users"
                    >
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-search me-1"></i>
                        Search
                    </button>
                    @if($search !== '')
                        <a href="{{ route('tech.admin.notification-channels.web-push.devices.index') }}" class="btn btn-outline-secondary">Reset</a>
                    @endif
                </div>
            </form>

            <p class="small text-muted">
                This inventory deliberately excludes subscription endpoints, public keys,
                authentication tokens, and VAPID secrets. Administrators can revoke devices but
                cannot register or enable Web Push for another user.
            </p>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Device</th>
                            <th>Browser / platform</th>
                            <th>Registered</th>
                            <th>Last seen</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($devices as $device)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $device->subscribable?->name ?? 'Unknown user' }}</div>
                                    <div class="small text-muted">{{ $device->subscribable?->email }}</div>
                                </td>
                                <td>{{ $device->device_label }}</td>
                                <td>{{ $device->browser_family }} / {{ $device->platform_family }}</td>
                                <td>{{ $device->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>{{ $device->last_seen_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('tech.admin.notification-channels.web-push.devices.destroy', $device) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Revoke this Web Push device?')">
                                            Revoke
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-muted">No registered Web Push devices match this view.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $devices->links() }}
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection
