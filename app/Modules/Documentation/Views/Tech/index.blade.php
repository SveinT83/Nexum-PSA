@extends('layouts.default_tech')

{{--
    Documentation Index View

    Displays a sortable/filterable list of all documentation records.
    Filtering is based on:
    1. Category (passed via 'cat' query parameter).
    2. Session-based Context (Active Client, Sites, or Internal Scope).
--}}

@section('title', 'Documentations')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Documentations</h1>
        <div>
            <x-buttons.back url="{{ route('tech.knowledge.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Search and context controls -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <form method="GET" action="{{ route('tech.documentations.index') }}" class="col-md-8">
                    <label for="documentation_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
                    <div class="input-group input-group-sm">
                        <input id="documentation_search" type="search" name="q" value="{{ $search }}" class="form-control" placeholder="Title, category, client, site, scope, or template">
                        <input type="hidden" name="cat" value="{{ request('cat', 'all') }}">
                        @if(request()->has('exclude_internal'))
                            <input type="hidden" name="exclude_internal" value="{{ request('exclude_internal') }}">
                        @endif
                        <button class="btn btn-outline-secondary" type="submit">Search</button>
                    </div>
                </form>

                <!-- Active Client Selector -->
                <div class="col-md-4">
                    <div class="form-label text-muted small fw-bold text-uppercase">Context</div>
                    <x-context.selector :clients="$clients" />
                </div>
            </div>

            @if(isset($selectedCategory))
                <div class="small text-muted mt-2">Category: {{ $selectedCategory->name }}</div>
            @endif
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Documentation list -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Documents</h2>
                <span class="badge text-bg-light border">{{ $documentations->total() }}</span>
            </div>
            <a href="{{ route('tech.documentations.create', ['cat' => request('cat', 'all')]) }}" class="btn btn-sm btn-primary mb-0">New Doc</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Client / Site</th>
                        <th>Scope</th>
                        <th>Template</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Iterate through documentation records and render table rows --}}
                    @forelse($documentations as $doc)
                        <tr class="cursor-pointer" data-href="{{ route('tech.documentations.show', $doc->id) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.documentations.show', $doc->id) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $doc->title }}
                                </a>
                            </td>
                            <td>
                                @if($doc->category)
                                    <span class="badge bg-secondary">{{ $doc->category->name }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($doc->client)
                                    {{ $doc->client->name }}
                                    @if($doc->site)
                                        <br><small class="text-muted">{{ $doc->site->name }}</small>
                                    @endif
                                @else
                                    <span class="text-muted">Internal</span>
                                @endif
                            </td>
                            <td>
                                @if($doc->scope_type == 'internal')
                                    <span class="badge bg-info">Internal</span>
                                @elseif($doc->scope_type == 'client')
                                    <span class="badge bg-primary">Client</span>
                                @elseif($doc->scope_type == 'site')
                                    <span class="badge bg-success">Site</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>{{ $doc->template->name ?? '—' }}</td>
                            <td>{{ $doc->updated_at->format('d.m.Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4">
                                <p class="text-muted mb-0">No documentations found.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($documentations->hasPages())
            <div class="card-footer">
                {{ $documentations->links() }}
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />

    <hr class="my-3">

    <x-nav.side-bar :items="$sidebarMenuItems" title="Documentation categories" />
@endsection

@section('rightbar')
@endsection
