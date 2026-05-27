@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Legal & Terms</h1>
        <div>
            <x-buttons.back url="{{ route('tech.sales.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Search controls -->
    <!-- ------------------------------------------------- -->
    <form method="GET" action="{{ route('tech.legal.index') }}" class="card mb-3">
        <div class="card-body">
            <label for="legal_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
            <div class="input-group input-group-sm">
                <input id="legal_search" type="search" name="search" value="{{ $search ?? '' }}" class="form-control" placeholder="Search name">
                <button class="btn btn-outline-secondary" type="submit">Search</button>
            </div>
        </div>
    </form>

    <!-- ------------------------------------------------- -->
    <!-- Legal and terms list -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Legal & Terms List</h2>
                <span class="badge text-bg-light border">{{ $terms->count() }}</span>
            </div>
            <x-buttons.addlink url="{{ route('tech.legal.create') }}" class="mb-0">New legal or term</x-buttons.addlink>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                </tr>
                </thead>
                <tbody>
                    @forelse($terms as $term)
                        <tr class="cursor-pointer" data-href="{{ route('tech.legal.show', $term) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.legal.show', $term) }}" class="fw-bold text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $term->name }}
                                </a>
                            </td>
                            <td><span class="badge text-bg-light border">{{ ucfirst($term->type) }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="text-center py-4">No terms and legals found.</td>
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
