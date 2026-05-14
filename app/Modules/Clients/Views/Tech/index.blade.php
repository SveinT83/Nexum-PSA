{{--
    Client List View

    This view displays a paginated table of all clients in the system.
    It includes a search bar to filter clients by name, organization number, or billing email.
    Each client in the list can be clicked to open their detailed view.
--}}
@extends('layouts.default_tech')

@section('pageHeader')

        <h1>Clients</h1>


@endsection

@section('content')

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Client Card whit Serarch form and new client button -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="card">

        <!-- ------------------------------------------------- -->
        <!-- Card Header -->
        <!-- ------------------------------------------------- -->
        <div class="card-header">

            <div class="row">

                <!-- Search Form -->
                <form method="get" class="col-md-10">
                    <div class="input-group">
                        <input type="text" name="search" value="{{ $search }}" class="form-control" placeholder="Search name / org no / email" />
                        <button class="btn btn-outline-secondary" type="submit">Search</button>
                    </div>
                </form>

                <!-- Button: New Client -->
                <div class="col-md-2 text-end">
                    <x-buttons.addlink url="{{ route('tech.clients.create') }}"> New Client</x-buttons.addlink>
                </div>

            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Card Body -->
        <!-- ------------------------------------------------- -->
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Org No</th>
                        <th>Billing Email</th>
                        <th>Risk Score</th>
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
                                @if($client->risk_score !== null)
                                    <span class="badge {{ $client->risk_score_badge_class }}">
                                        {{ $client->risk_score }}
                                    </span>
                                @else
                                    <span class="text-muted small">N/A</span>
                                @endif
                            </td>
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
                            <td colspan="6" class="text-center py-4">No clients found.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                {{ $clients->links() }}
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" title="Client workspace" />
    @endif
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent clients (MVP later)</div>
@endsection
