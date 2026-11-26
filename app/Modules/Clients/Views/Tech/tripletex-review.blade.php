@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Review Tripletex customer link</h1>
        <x-buttons.back :url="route('tech.clients.create')">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    {{-- This staff page displays only the selected customer pair. --}}
    @include('clients::Tech.tripletex-comparison')
@endsection
