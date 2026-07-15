@extends('booking::layouts.public')

@section('title', 'Booking request received')
@section('eyebrow', 'Online booking')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Public Booking Thanks -->
    <!-- ------------------------------------------------- -->
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <div class="display-6 text-success mb-3">
                <i class="bi bi-check-circle" aria-hidden="true"></i>
            </div>
            <h1 class="h4">Booking request received</h1>
            <p class="text-muted mb-3">We will confirm the appointment after review.</p>
            @if(session('booking_request_key'))
                <div class="badge text-bg-light border mb-4">Reference {{ session('booking_request_key') }}</div>
            @endif
            <div>
                <a href="{{ route('booking.services.show', $setting) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-calendar-plus" aria-hidden="true"></i>
                    Request another time
                </a>
            </div>
        </div>
    </div>
@endsection
