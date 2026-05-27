@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>SLA</h1>
        <div>
            <x-buttons.back url="{{ route('tech.sales.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Search controls -->
    <!-- ------------------------------------------------- -->
    <form method="GET" action="{{ route('tech.sla.index') }}" class="card mb-3">
        <div class="card-body">
            <label for="sla_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
            <div class="input-group input-group-sm">
                <input id="sla_search" type="search" name="search" value="{{ $search ?? '' }}" class="form-control" placeholder="Search name">
                <button class="btn btn-outline-secondary" type="submit">Search</button>
            </div>
        </div>
    </form>

    <!-- ------------------------------------------------- -->
    <!-- SLA list -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">SLA Policy List</h2>
                <span class="badge text-bg-light border">{{ $sla->count() }}</span>
            </div>
            <x-buttons.addlink url="{{ route('tech.sla.create') }}" class="mb-0">New SLA policy</x-buttons.addlink>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                </tr>
                </thead>
                <tbody>
                    @forelse($sla as $slax)
                        <tr class="cursor-pointer" data-href="{{ route('tech.sla.show', $slax) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.sla.show', $slax) }}" class="fw-bold text-decoration-none" onclick="event.stopPropagation()">{{ $slax->name }}</a>
                                @if($slax->is_default)
                                    <span class="badge text-bg-primary ms-1">Default</span>
                                @endif
                            </td>
                            <td>
                                @if(filled($slax->description))
                                    {{ \Illuminate\Support\Str::limit($slax->description, 120) }}
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="text-center py-4">No SLA policies found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
@endsection
