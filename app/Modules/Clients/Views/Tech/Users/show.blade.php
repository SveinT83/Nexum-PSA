@extends('layouts.default_tech')

@php
    $backUrl = route('tech.clients.user_management.index');
    if (isset($user->site->client_id)) {
        $backUrl = route('tech.clients.show', $user->site->client_id);
    }

    $missing = fn ($value) => filled($value) ? $value : '—';
@endphp

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>{{ $user->name ?? 'Unknown' }}</h1>
        <x-buttons.back url="{{ $backUrl }}" class="btn btn-sm btn-outline-secondary bi bi-arrow-left mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    {{--
        Client User Details
        Organizes contact information, site context, and address data for the
        selected client contact.
    --}}
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <h2 class="h5 mb-0">User Profile</h2>
            <x-buttons.editlink url="{{ route('tech.clients.user.edit', $user) }}" class="btn btn-sm btn-outline-secondary bi bi-pencil mb-0">Edit User</x-buttons.editlink>
        </div>
        <div class="card-body">
            <!-- ------------------------------------------------- -->
            <!-- Contact and relationship profile -->
            <!-- ------------------------------------------------- -->
            <div class="row g-3">
                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8">{{ $missing($user->name) }}</dd>

                        <dt class="col-sm-4">Role</dt>
                        <dd class="col-sm-8 {{ blank($user->role) ? 'text-muted' : '' }}">{{ $missing($user->role) }}</dd>

                        <dt class="col-sm-4">Email</dt>
                        <dd class="col-sm-8 {{ blank($user->email) ? 'text-muted' : '' }}">
                            @if(filled($user->email))
                                <a href="mailto:{{ $user->email }}">{{ $user->email }}</a>
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-4">Phone</dt>
                        <dd class="col-sm-8 {{ blank($user->phone) ? 'text-muted' : '' }}">
                            @if(filled($user->phone))
                                <a href="tel:{{ $user->phone }}">{{ $user->phone }}</a>
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-4">Site</dt>
                        <dd class="col-sm-8 {{ blank($user->site?->name) ? 'text-muted' : '' }}">
                            @if($user->site)
                                <a href="{{ route('tech.clients.sites.show', $user->site) }}">{{ $user->site->name }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </dl>
                </div>
                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Address</dt>
                        <dd class="col-sm-8 {{ blank($user->address) ? 'text-muted' : '' }}">{{ $missing($user->address) }}</dd>

                        <dt class="col-sm-4">CO Address</dt>
                        <dd class="col-sm-8 {{ blank($user->co_address) ? 'text-muted' : '' }}">{{ $missing($user->co_address) }}</dd>

                        <dt class="col-sm-4">Zip / City</dt>
                        <dd class="col-sm-8 {{ blank($user->zip) && blank($user->city) ? 'text-muted' : '' }}">
                            @if(filled($user->zip) || filled($user->city))
                                {{ trim(($user->zip ?? '').' '.($user->city ?? '')) }}
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-4">County / Country</dt>
                        <dd class="col-sm-8 {{ blank($user->county) && blank($user->country) ? 'text-muted' : '' }}">
                            @if(filled($user->county) || filled($user->country))
                                {{ trim(($user->county ?? '').' '.($user->country ?? '')) }}
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-4">Language</dt>
                        <dd class="col-sm-8">{{ strtoupper($user->language ?? 'NO') }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" title="Client workspace" />
    @endif
@endsection
