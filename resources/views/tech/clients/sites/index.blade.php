@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Sites for {{$client->name ?? 'all clients'}}</h2>
        <div>
            <a href="{{ route('tech.clients.sites.create', $client ?? 'null') }}" class="btn btn-sm btn-primary">New Site</a>
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
                <th>Addrress</th>
                <th>Zip</th>
                <th>City</th>
                <th>Client</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($sites as $site)
                <tr>
                    <td>
                        <a href="{{ route('tech.clients.sites.show', $site) }}" class="text-decoration-none">{{ $site->name }}</a>
                    </td>
                    <td>{{$site->address}}</td>
                    <td>{{$site->zip}}</td>
                    <td>{{$site->city}}</td>
                    <td>{{$site->client->name}}</td>
                    <td>
                        <div class="row me-1 justify-content-end">
                            <div class="col-auto">
                                <x-buttons.editlink url="{{ route('tech.clients.sites.edit', [$site, $client]) }}">Edit</x-buttons.editlink>
                            </div>
                            <div class="col-auto">
                                <x-buttons.delete
                                    url="{{ route('tech.clients.sites.destroy', $site) }}"
                                    name="{{ $site->name }}">
                                </x-buttons.delete>
                            </div>
                        </div>
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
