@extends('layouts.default_tech')

@section('title', 'Edit Ticket Assignment Settings')

<!-- Page header: admin edit for one technician's ticket assignment settings. -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Edit Ticket Assignment Settings</h1>
        <x-buttons.back url="{{ route('tech.admin.settings.tickets.technicians') }}">Back</x-buttons.back>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="tickets" />
@endsection

@section('content')
    @include('ticket::Shared.TicketAssignmentSettings.form', [
        'action' => route('tech.admin.settings.tickets.technicians.update', $profile),
        'method' => 'PATCH',
        'submitLabel' => 'Save settings',
        'showUser' => true,
    ])
@endsection
