@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Users</h2>
        <div>
            <a href="{{ route('tech.clients.create') }}" class="btn btn-sm btn-primary">New Client</a>
        </div>
    </div>
    <form method="get" class="mb-3">
        <div class="input-group">
            <input type="text" name="search" value="{{ $search }}" class="form-control" placeholder="Search name / org no / email" />
            <button class="btn btn-outline-secondary" type="submit">Search</button>
        </div>
    </form>
@endsection

@section('content')
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
            <tr>
                <th>Name</th>
                @if(!isset($client))
                    <th>Client</th>
                @endif
                <th>Site</th>
                <th>role</th>
                <th>Email</th>
                <th>Phone</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($users as $user)
                <tr>
                    <td><a href="{{route('tech.clients.user.show', $user)}}">{{$user->name}}</a></td>
                    @if(!isset($client))
                        <td>{{ $user->site->client->name ?? '-' }}</td>
                    @endif
                    <td>{{$user->site->name}}</td>
                    <td>{{$user->role}}</td>
                    <td><a href="mailto:{{$user->email}}">{{$user->email}}</a></td>
                    <td><a href="tel:{{$user->phone}}">{{$user->phone}}</a></td>
                    <td>
                        <x-buttons.editlink url="{{ route('tech.clients.user.edit', [$user, $client]) }}">Edit</x-buttons.editlink>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center py-4">No sites found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection

@section('sidebar')

@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent clients (MVP later)</div>
@endsection
