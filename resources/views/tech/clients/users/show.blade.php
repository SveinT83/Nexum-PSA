@extends('layouts.default_tech')

@section('pageHeader')
    {{--
        Client User Detail Header
        Provides a clear overview of the user and actions to navigate or edit.
    --}}
    <div class="d-flex justify-content-between align-items-center py-3">
        <h1 class="mb-0">{{$user->name ?? 'Unknown'}}</h1>
        <div>
            @php
                /**
                 * Navigation Logic:
                 * Default back URL is the users index.
                 * If the user is linked to a site (which belongs to a client), we prioritize returning to the client profile.
                 */
                $backUrl = route('tech.clients.users.index');
                if (isset($user->site->client_id)) {
                    $backUrl = route('tech.clients.show', $user->site->client_id);
                }
            @endphp

            {{-- Navigation and Action Buttons --}}
            <x-buttons.back url="{{ $backUrl }}">Back to Client</x-buttons.back>

            @if(isset($user->client_site_id))
                {{-- Link to the specific Site this user belongs to --}}
                <a href="{{ route('tech.clients.sites.show', $user->client_site_id) }}" class="btn btn-sm btn-outline-secondary mb-3 bi bi-building">
                    Go to Site: {{ $user->site->name ?? 'Site Detail' }}
                </a>
            @endif

            <x-buttons.editlink url="{{ route('tech.clients.user.edit', $user) }}">Edit User</x-buttons.editlink>
        </div>
    </div>
@endsection

@section('content')
    {{--
        Client User Details
        Organizes contact information and address data for the specific user.
    --}}
    <div class="row">

        <!-- ------------------------------------------------- -->
        <!-- Contact info -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-6">
            <div class="row mt-3">
                <div class="col-md-auto">
                    {{-- Quick contact buttons (Phone/Email) --}}
                    <x-buttons.tel tel="{{$user->phone}}"></x-buttons.tel>
                </div>
                <div class="col-md-auto">
                    <x-buttons.mailto email="{{$user->email}}"></x-buttons.mailto>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Address info -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-6">
            <p><b>Address:</b> {{$user->address ?? '-'}}</p>
            <p><b>CO Address:</b> {{$user->co_address ?? '-'}}</p>
            <p>{{$user->zip ?? '-'}} {{$user->city ?? '-'}}</p>
            <p>{{$user->county ?? '-'}} {{$user->country ?? '-'}}</p>
            <p><b>Language:</b> {{ strtoupper($user->language ?? 'NO') }}</p>
        </div>

    </div>
@endsection

@section('sidebar')
    {{-- Sidebar intentionally left empty for future contextual menu items --}}
@endsection

@section('rightbar')
    {{-- Right bar for contextual widgets, currently placeholder --}}
    <div class="p-3 small text-muted">Recent activities (MVP later)</div>
@endsection

