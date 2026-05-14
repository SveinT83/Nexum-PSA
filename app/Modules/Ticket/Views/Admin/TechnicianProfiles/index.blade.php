@extends('layouts.default_tech')

@section('title', 'Ticket Technician Profiles')

<!-- Page header: admin-owned technician assignment setup under Ticket settings. -->
@section('pageHeader')
    <h1>Ticket Technician Profiles</h1>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <!-- Profile creation: admins can opt active users into future assignment without leaving Ticket settings. -->
    <x-card.default title="Create profile">
        <form method="POST" action="{{ route('tech.admin.settings.tickets.technicians.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-8">
                <label for="user_id" class="form-label">Technician</label>
                <select id="user_id" name="user_id" class="form-select" @disabled($techniciansWithoutProfiles->isEmpty())>
                    @foreach($techniciansWithoutProfiles as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} - {{ $user->email }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary" @disabled($techniciansWithoutProfiles->isEmpty())>Create profile</button>
            </div>
        </form>
    </x-card.default>

    <!-- Profile list: capacity and skills are visible before assignment automation is introduced. -->
    <x-card.default title="Profiles">
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Technician</th>
                        <th>Assignable</th>
                        <th>Capacity</th>
                        <th>Skills</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($profiles as $profile)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $profile->user?->name }}</div>
                                <div class="small text-muted">{{ $profile->user?->email }}</div>
                            </td>
                            <td>{{ $profile->is_assignable ? 'Yes' : 'No' }}</td>
                            <td>{{ $openTicketCounts[$profile->user_id] ?? 0 }} / {{ $profile->max_open_tickets }}</td>
                            <td class="small">
                                {{ $profile->categories->pluck('name')->merge($profile->tags->pluck('name'))->take(6)->implode(', ') ?: '-' }}
                            </td>
                            <td class="text-end">
                                <a href="{{ route('tech.admin.settings.tickets.technicians.edit', $profile) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No technician profiles yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card.default>
@endsection

@section('sidebar')
    <h3>Ticket Settings</h3>
    <ul>
        <li><a href="{{ route('tech.admin.settings.tickets') }}">Tickets</a></li>
        <li><a href="{{ route('tech.admin.settings.tickets.technicians') }}">Technicians</a></li>
        <li><a href="{{ route('tech.admin.settings.tickets.assignment-rules') }}">Assignment Rules</a></li>
        <li><a href="{{ route('tech.admin.settings.tickets.rules') }}">Rules</a></li>
        <li><a href="{{ route('tech.admin.settings.tickets.workflows') }}">Workflows</a></li>
    </ul>
@endsection
