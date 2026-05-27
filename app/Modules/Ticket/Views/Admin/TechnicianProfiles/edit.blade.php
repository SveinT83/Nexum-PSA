@extends('layouts.default_tech')

@section('title', 'Edit Technician Profile')

<!-- Page header: admin edit for one technician profile. -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Edit Technician Profile</h1>
        <x-buttons.back url="{{ route('tech.admin.settings.tickets.technicians') }}">Back</x-buttons.back>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="tickets" />
@endsection

@section('content')
    @include('ticket::Shared.TechnicianProfiles.form', [
        'action' => route('tech.admin.settings.tickets.technicians.update', $profile),
        'method' => 'PATCH',
        'submitLabel' => 'Save profile',
        'showUser' => true,
    ])
@endsection
