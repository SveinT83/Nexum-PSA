@extends('booking::layouts.public')

@section('title', 'Booking')
@section('eyebrow', 'Online booking')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Public Booking Service List -->
    <!-- ------------------------------------------------- -->
    <div class="card shadow-sm">
        <div class="card-header bg-body">
            <h1 class="h4 mb-0">Book an appointment</h1>
        </div>
        <div class="card-body">
            @if($settings->isNotEmpty())
                <div class="list-group list-group-flush">
                    @foreach($settings as $setting)
                        <a href="{{ route('booking.services.show', $setting) }}" class="list-group-item list-group-item-action px-0">
                            <div class="d-flex justify-content-between gap-3">
                                <div>
                                    <div class="fw-semibold">{{ $setting->publicTitle() }}</div>
                                    @if($setting->public_description)
                                        <div class="small text-muted">{{ $setting->public_description }}</div>
                                    @endif
                                </div>
                                <div class="text-end small text-muted flex-shrink-0">
                                    {{ $setting->durationLabel() }}
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="text-center text-muted py-4">No online booking services are currently available.</div>
            @endif
        </div>
    </div>
@endsection
