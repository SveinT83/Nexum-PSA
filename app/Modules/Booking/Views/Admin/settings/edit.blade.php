@extends('layouts.default_tech')

@section('title', 'Edit booking service')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">Edit booking service</h1>
    </div>
    @if($setting->isBookable())
        <div class="col-auto">
            <a href="{{ route('booking.services.show', $setting) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                Public page
            </a>
        </div>
    @endif
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Booking Service Edit Form -->
    <!-- ------------------------------------------------- -->
    @include('booking::Admin.settings._form', ['setting' => $setting, 'services' => $services, 'users' => $users])
@endsection

@section('sidebar')
    <x-nav.admin-menu group="booking" />
@endsection

@section('rightbar')
@endsection
