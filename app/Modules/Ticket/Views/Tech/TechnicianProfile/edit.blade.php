@extends('layouts.default_tech')

@section('title', 'Ticket Technician Profile')

@section('sidebar')
    @include('usermanagement::profile.partials.sidebar')
@endsection

<!-- Page header: technicians maintain their own assignment profile from the Ticket module. -->
@section('pageHeader')
    <h1>Ticket Technician Profile</h1>
@endsection

@section('content')
    @include('ticket::Shared.TechnicianProfiles.form', [
        'action' => route('tech.tickets.profile.update'),
        'method' => 'PATCH',
        'submitLabel' => 'Save profile',
        'showUser' => false,
    ])
@endsection

@section('rightbar')
    <x-card.default title="Assignment note">
        <p class="small text-muted mb-0">
            These skills and hours will be used by future ticket assignment scoring. Admins can still override profiles from Ticket Settings.
        </p>
    </x-card.default>
@endsection
