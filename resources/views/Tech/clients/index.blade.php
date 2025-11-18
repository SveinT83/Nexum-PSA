@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Clients</h2>
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
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
            <tr>
                <th>Name</th>
                <th>Org No</th>
                <th>Billing Email</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($clients as $client)
                <tr>
                    <td>
                        <a href="{{ route('tech.clients.show', $client) }}" class="text-decoration-none">{{ $client->name }}</a>
                    </td>
                    <td>{{ $client->org_no ?? '—' }}</td>
                    <td>{{ $client->billing_email ?? '—' }}</td>
                    <td>
                        @if($client->active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('tech.clients.show', $client) }}" class="btn btn-sm btn-outline-primary">Open</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center py-4">No clients found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $clients->links() }}
    </div>
@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Client filters (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent clients (MVP later)</div>
@endsection