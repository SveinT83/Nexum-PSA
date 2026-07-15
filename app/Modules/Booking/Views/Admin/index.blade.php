@extends('layouts.default_tech')

@section('title', 'Booking')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">Booking</h1>
    </div>
    <div class="col-auto">
        <a href="{{ route('tech.admin.system.booking.settings.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-circle" aria-hidden="true"></i>
            New booking service
        </a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Booking Admin Overview -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Open requests</h2>
                </div>
                <div class="card-body">
                    <div class="display-6">{{ $openRequestCount }}</div>
                    <div class="text-muted small">Booking requests awaiting staff review.</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Booking services</h2>
                </div>
                <div class="card-body">
                    <div class="display-6">{{ $settings->count() }}</div>
                    <div class="text-muted small">Commercial services configured for public booking.</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Active services</h2>
                </div>
                <div class="card-body">
                    <div class="display-6">{{ $settings->filter->isBookable()->count() }}</div>
                    <div class="text-muted small">Services currently exposed on the public booking page.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-body d-flex align-items-center justify-content-between gap-2">
            <h2 class="h6 mb-0">Booking services</h2>
            <a href="{{ route('tech.admin.system.booking.settings.create') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-plus-circle" aria-hidden="true"></i>
                New service
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Technician</th>
                        <th>Duration</th>
                        <th class="text-end">Requests</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($settings as $setting)
                        <tr>
                            <td>
                                <a href="{{ route('tech.admin.system.booking.settings.edit', $setting) }}" class="fw-semibold text-decoration-none">{{ $setting->publicTitle() }}</a>
                                <div class="small text-muted">/booking/{{ $setting->slug }}</div>
                            </td>
                            <td>
                                <span class="badge {{ $setting->isBookable() ? 'text-bg-success' : 'text-bg-light border' }}">
                                    {{ $setting->isBookable() ? 'Bookable' : ucfirst($setting->status) }}
                                </span>
                            </td>
                            <td>{{ $setting->assignedUser?->name ?? 'Not assigned' }}</td>
                            <td>{{ $setting->durationLabel() }}</td>
                            <td class="text-end">{{ $setting->requests_count }}</td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    @if($setting->isBookable())
                                        <a href="{{ route('booking.services.show', $setting) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Open public booking">
                                            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                        </a>
                                    @endif
                                    <a href="{{ route('tech.admin.system.booking.settings.edit', $setting) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                    <form method="POST" action="{{ route('tech.admin.system.booking.settings.toggle', $setting) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            {{ $setting->isActive() ? 'Disable' : 'Enable' }}
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No booking services configured.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-body">
            <h2 class="h6 mb-0">Latest requests</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Requested time</th>
                        <th>Service</th>
                        <th>Status</th>
                        <th>Customer</th>
                        <th>Technician</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($requests as $bookingRequest)
                        <tr>
                            <td class="small">{{ $bookingRequest->slotLabel() }}</td>
                            <td>{{ $bookingRequest->setting?->publicTitle() ?? $bookingRequest->service?->name ?? 'Deleted service' }}</td>
                            <td><span class="badge {{ $bookingRequest->statusBadgeClass() }}">{{ ucfirst($bookingRequest->status) }}</span></td>
                            <td>
                                <a href="{{ route('tech.admin.system.booking.requests.show', $bookingRequest) }}" class="fw-semibold text-decoration-none">{{ $bookingRequest->contact_name }}</a>
                                <div class="small text-muted">{{ $bookingRequest->company_name ?: $bookingRequest->contact_email }}</div>
                            </td>
                            <td>{{ $bookingRequest->assignedUser?->name ?? 'Not assigned' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No booking requests yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="booking" />
@endsection

@section('rightbar')
@endsection
