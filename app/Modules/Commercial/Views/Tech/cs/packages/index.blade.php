@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Packages</h2>
        <div>
            <a href="{{ route('tech.packages.create') }}" class="btn btn-sm btn-primary">New Package</a>
        </div>
    </div>
@endsection

@section('content')

    <x-card.default title="Packages">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Services</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($packages as $package)
                    <tr>
                        <td>
                            <a href="{{ route('tech.packages.show', $package) }}"><b>{{ $package->name }}</b></a>
                        </td>
                        <td>{{ Str::limit($package->description, 50) }}</td>
                        <td><span class="badge bg-info text-dark">{{ $package->services_count }} services</span></td>
                        <td>
                            <span class="badge bg-{{ $package->status === 'active' ? 'success' : 'secondary' }}">
                                {{ ucfirst($package->status) }}
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="btn-group">
                                <a href="{{ route('tech.packages.edit', $package) }}" class="btn btn-sm btn-outline-primary bi bi-pencil"></a>
                                <form action="{{ route('tech.packages.delete', $package) }}" method="POST" onsubmit="return confirm('Are you sure?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger bi bi-trash"></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center">No packages found. <a href="{{ route('tech.packages.create') }}">Create one?</a></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card.default>

@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Packages filters (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent Packages (MVP later)</div>
@endsection
