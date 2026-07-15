@extends('booking::layouts.public')

@section('title', $setting->publicTitle())
@section('eyebrow', 'Online booking')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Public Booking Form -->
    <!-- ------------------------------------------------- -->
    <div class="card shadow-sm">
        <div class="card-header bg-body">
            <h1 class="h4 mb-0">{{ $setting->publicTitle() }}</h1>
        </div>
        <div class="card-body">
            @if($setting->public_description)
                <p class="text-muted mb-4">{{ $setting->public_description }}</p>
            @endif

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">Duration</div>
                        <div class="fw-semibold">{{ $setting->durationLabel() }}</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">Confirmation</div>
                        <div class="fw-semibold">Staff confirmed</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">Time zone</div>
                        <div class="fw-semibold">{{ $timezone }}</div>
                    </div>
                </div>
            </div>

            <form method="GET" action="{{ route('booking.services.show', $setting) }}" class="row g-2 align-items-end mb-4">
                <div class="col-sm-6">
                    <label for="booking_date" class="form-label">Start date</label>
                    <input type="date" id="booking_date" name="date" value="{{ $selectedDate }}" class="form-control">
                </div>
                <div class="col-sm-auto">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        Find times
                    </button>
                </div>
            </form>

            <form method="POST" action="{{ route('booking.services.store', $setting) }}">
                @csrf

                <div class="booking-honeypot" aria-hidden="true">
                    <label for="{{ $setting->spam_honeypot_field }}">Website</label>
                    <input type="text" id="{{ $setting->spam_honeypot_field }}" name="{{ $setting->spam_honeypot_field }}" value="" tabindex="-1" autocomplete="off">
                </div>

                <input type="hidden" name="timezone" value="{{ $timezone }}">

                <div class="mb-4">
                    <label class="form-label">Available times <span class="text-danger">*</span></label>
                    @if($slots->isNotEmpty())
                        <div class="row g-2">
                            @foreach($slots as $slot)
                                @php
                                    $slotValue = $slot['starts_at']->toIso8601String();
                                    $slotId = 'booking_slot_'.$loop->index;
                                @endphp
                                <div class="col-md-6">
                                    <input type="radio" class="btn-check @error('slot_starts_at') is-invalid @enderror" name="slot_starts_at" id="{{ $slotId }}" value="{{ $slotValue }}" @checked(old('slot_starts_at') === $slotValue) required>
                                    <label class="btn btn-outline-primary w-100 text-start" for="{{ $slotId }}">
                                        <span class="fw-semibold">{{ $slot['starts_at']->format('D, M j') }}</span>
                                        <span class="d-block small">{{ $slot['starts_at']->format('H:i') }} - {{ $slot['ends_at']->format('H:i') }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="alert alert-warning mb-0">No available times were found in this period.</div>
                    @endif
                    @error('slot_starts_at')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="contact_name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" id="contact_name" name="contact_name" value="{{ old('contact_name') }}" class="form-control @error('contact_name') is-invalid @enderror" required>
                        @error('contact_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="contact_email" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" id="contact_email" name="contact_email" value="{{ old('contact_email') }}" class="form-control @error('contact_email') is-invalid @enderror" required>
                        @error('contact_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="company_name" class="form-label">Company</label>
                        <input type="text" id="company_name" name="company_name" value="{{ old('company_name') }}" class="form-control @error('company_name') is-invalid @enderror">
                        @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="contact_phone" class="form-label">Phone</label>
                        <input type="tel" id="contact_phone" name="contact_phone" value="{{ old('contact_phone') }}" class="form-control @error('contact_phone') is-invalid @enderror">
                        @error('contact_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label for="message" class="form-label">Message</label>
                        <textarea id="message" name="message" rows="4" class="form-control @error('message') is-invalid @enderror">{{ old('message') }}</textarea>
                        @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" id="privacy_acknowledged" name="privacy_acknowledged" value="1" class="form-check-input @error('privacy_acknowledged') is-invalid @enderror" @checked(old('privacy_acknowledged')) required>
                            <label for="privacy_acknowledged" class="form-check-label">I agree that this information is used to process my booking request.</label>
                            @error('privacy_acknowledged')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                @if($setting->instructions)
                    <div class="alert alert-info mt-4 mb-0">{{ $setting->instructions }}</div>
                @endif

                <div class="d-flex justify-content-between align-items-center gap-2 mt-4">
                    <a href="{{ route('booking.index') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                        Back
                    </a>
                    <button type="submit" class="btn btn-primary" @disabled($slots->isEmpty())>
                        <i class="bi bi-send" aria-hidden="true"></i>
                        Send booking request
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
