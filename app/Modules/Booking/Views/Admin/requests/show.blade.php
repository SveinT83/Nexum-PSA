@extends('layouts.default_tech')

@section('title', 'Booking request '.$bookingRequest->booking_key)

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">Booking request {{ $bookingRequest->booking_key }}</h1>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Booking Request Review -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-body d-flex align-items-center justify-content-between gap-2">
                    <h2 class="h6 mb-0">Request details</h2>
                    <span class="badge {{ $bookingRequest->statusBadgeClass() }}">{{ ucfirst($bookingRequest->status) }}</span>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Service</dt>
                        <dd class="col-sm-9">{{ $bookingRequest->setting?->publicTitle() ?? $bookingRequest->service?->name ?? 'Deleted service' }}</dd>

                        <dt class="col-sm-3">Requested time</dt>
                        <dd class="col-sm-9">{{ $bookingRequest->slotLabel() }}</dd>

                        <dt class="col-sm-3">Technician</dt>
                        <dd class="col-sm-9">{{ $bookingRequest->assignedUser?->name ?? $bookingRequest->setting?->assignedUser?->name ?? 'Not assigned' }}</dd>

                        <dt class="col-sm-3">Company</dt>
                        <dd class="col-sm-9">{{ $bookingRequest->company_name ?: 'Not provided' }}</dd>

                        <dt class="col-sm-3">Contact</dt>
                        <dd class="col-sm-9">
                            {{ $bookingRequest->contact_name }}
                            <div class="small text-muted">{{ $bookingRequest->contact_email }}{{ $bookingRequest->contact_phone ? ' / '.$bookingRequest->contact_phone : '' }}</div>
                        </dd>

                        <dt class="col-sm-3">Message</dt>
                        <dd class="col-sm-9">{{ $bookingRequest->message ?: 'No message' }}</dd>

                        @if($bookingRequest->calendarEvent)
                            <dt class="col-sm-3">Calendar event</dt>
                            <dd class="col-sm-9">#{{ $bookingRequest->calendarEvent->id }} {{ $bookingRequest->calendarEvent->title }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Timeline</h2>
                </div>
                <div class="list-group list-group-flush">
                    @forelse($bookingRequest->events as $event)
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between gap-3">
                                <div>
                                    <div class="fw-semibold">{{ ucfirst(str_replace('_', ' ', $event->type)) }}</div>
                                    @if($event->message)
                                        <div class="small text-muted">{{ $event->message }}</div>
                                    @endif
                                </div>
                                <div class="text-end small text-muted flex-shrink-0">
                                    {{ $event->created_at?->format('Y-m-d H:i') }}
                                    @if($event->actor)
                                        <div>{{ $event->actor->name }}</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="list-group-item text-center text-muted py-4">No events recorded.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Review</h2>
                </div>
                <div class="card-body">
                    @if($bookingRequest->isRequested())
                        <form method="POST" action="{{ route('tech.admin.system.booking.requests.confirm', $bookingRequest) }}" class="mb-3">
                            @csrf
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-calendar-check" aria-hidden="true"></i>
                                Confirm and create Calendar event
                            </button>
                        </form>

                        <form method="POST" action="{{ route('tech.admin.system.booking.requests.decline', $bookingRequest) }}">
                            @csrf
                            <div class="mb-3">
                                <label for="decline_reason" class="form-label">Decline reason</label>
                                <textarea id="decline_reason" name="decline_reason" rows="3" class="form-control @error('decline_reason') is-invalid @enderror">{{ old('decline_reason') }}</textarea>
                                @error('decline_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-outline-secondary w-100">
                                <i class="bi bi-x-circle" aria-hidden="true"></i>
                                Decline request
                            </button>
                        </form>
                    @else
                        <p class="text-muted mb-0">This request has already been reviewed.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="booking" />
@endsection

@section('rightbar')
@endsection
