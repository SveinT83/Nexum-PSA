@extends('layouts.default_tech')

@section('title', 'Ticket Assignment Settings')

@section('sidebar')
    @include('usermanagement::profile.partials.sidebar')
@endsection

<!-- Page header: technicians maintain ticket-specific assignment settings from the Ticket module. -->
@section('pageHeader')
    <h1>Ticket Assignment Settings</h1>
@endsection

@section('content')
    @include('ticket::Shared.TechnicianProfiles.form', [
        'action' => route('tech.tickets.profile.update'),
        'method' => 'PATCH',
        'submitLabel' => 'Save settings',
        'showUser' => false,
    ])
@endsection

@section('rightbar')
    <x-card.default title="Assignment note">
        <p class="small text-muted mb-0">
            These ticket-specific capacity and matching settings are used by assignment scoring.
            Work hours and timezone are managed from the main profile.
        </p>
    </x-card.default>
@endsection
