@extends('layouts.default_tech')

{{--
    Documentation Index View

    Displays a sortable/filterable list of all documentation records.
    Filtering is based on:
    1. Category (passed via 'cat' query parameter).
    2. Session-based Context (Active Client, Site, or Internal Scope).
--}}

@section('title', 'Documentations')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h1 class="h4 mb-0">
            Documentations
            @if(isset($selectedCategory))
                <span class="text-muted fs-6 ms-2">/ {{ $selectedCategory->name }}</span>
            @endif
        </h1>

        <!-- Active Client Selector -->
        <div class="d-flex align-items-center">
            <x-context.selector :clients="$clients" />
        </div>

        <div>
            <a href="{{ route('tech.documentations.create', ['cat' => request('cat', 'all')]) }}" class="btn btn-sm btn-primary">New Doc</a>
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Client / Site</th>
                                <th>Scope</th>
                                <th>Template</th>
                                <th>Last Updated</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Iterate through documentation records and render table rows --}}
                            @forelse($documentations as $doc)
                                <tr onclick="window.location='{{ route('tech.documentations.show', $doc->id) }}'" style="cursor: pointer;">
                                    <td>
                                        <strong>{{ $doc->title }}</strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">{{ $doc->category->name ?? 'N/A' }}</span>
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
                                        @endif
                                    </td>
                                    <td>{{ $doc->template->name ?? 'N/A' }}</td>
                                    <td>{{ $doc->updated_at->format('d.m.Y H:i') }}</td>
                                    <td class="text-end">
                                        <div class="btn-group">
                                            <a href="{{ route('tech.documentations.show', $doc->id) }}" class="btn btn-sm btn-outline-primary">View</a>
                                            <a href="{{ route('tech.documentations.edit', $doc->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4">
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
        </div>
    </div>
@endsection

@section('sidebar')

@endsection

@section('rightbar')
    <h3>Right Sidebar</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection
