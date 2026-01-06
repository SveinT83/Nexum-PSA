@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Services</h2>
        <div>
            <a href="{{ route('tech.services.create') }}" class="btn btn-sm btn-primary">New Service</a>
        </div>
    </div>
    <form method="get" class="mb-3">
        <div class="input-group">
            <input type="text" name="search" value="{{ $search }}" class="form-control" placeholder="Search name / SKU" />
            <button class="btn btn-outline-secondary" type="submit">Search</button>
        </div>
    </form>
@endsection

@section('content')

    <!-- ------------------------------------------------- -->
    <!-- Alert message -->
    <!-- ------------------------------------------------- -->
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
            <tr>
                <th></th>
                <th>SKU</th>
                <th>Name</th>
                <th>Price</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($services as $service)
                <tr>
                    <td>
                        <a href="{{ route('tech.services.show', $service) }}" class="text-decoration-none">{{ $service->name }}</a>
                    </td>
                    <td>{{ $service->sku ?? '—' }}</td>
                    <td>{{ $service->name ?? '—' }}</td>
                    <td>{{ $service->price_ex_vat ?? '' }}</td>
                    <td>
                        @if($service->status == 'Active')
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('tech.services.show', $service) }}" class="btn btn-sm btn-outline-primary">Open</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center py-4">No services found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $services->links() }}
    </div>
@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Service filters (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent services (MVP later)</div>
@endsection
