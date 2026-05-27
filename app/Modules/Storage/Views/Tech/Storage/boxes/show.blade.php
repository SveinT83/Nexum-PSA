@extends('layouts.default_tech')

@section('title', 'Storage Box #' . $box->id)

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.storage.index') }}">Storage</a></li>
            <li class="breadcrumb-item active" aria-current="page">Box #{{ $box->id }}</li>
        </ol>
    </nav>
    <h1>Box #{{ $box->id }} {{ $box->name ? '- ' . $box->name : '' }}</h1>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="text-muted small">Warehouse</div>
                        <div class="fw-semibold">{{ $box->warehouse->name }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Code</div>
                        <div class="fw-semibold">{{ $box->code_human ?: '-' }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Barcode</div>
                        <div class="fw-semibold">{{ $box->barcode_value }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Status</div>
                        <span class="badge text-bg-secondary">{{ str_replace('_', ' ', ucfirst($box->status)) }}</span>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Placement</div>
                        <div>{{ $box->placement_note ?: '-' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Contents</h5></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Name</th>
                        <th class="text-end">On-hand</th>
                        <th class="text-end">Reserved</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($box->items as $item)
                        <tr>
                            <td>{{ $item->sku }}</td>
                            <td>{{ $item->name }}</td>
                            <td class="text-end">{{ $item->qty_on_hand }}</td>
                            <td class="text-end">{{ $item->qty_reserved }}</td>
                            <td class="text-end"><a href="{{ route('tech.storage.items.show', $item) }}" class="btn btn-sm btn-outline-primary">View</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">This box is empty.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">Box Events</h5></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th>When</th>
                        <th>Type</th>
                        <th>Actor</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($box->events->sortByDesc('created_at') as $event)
                        <tr>
                            <td>{{ $event->created_at->format('Y-m-d H:i') }}</td>
                            <td>{{ str_replace('_', ' ', ucfirst($event->type)) }}</td>
                            <td>{{ $event->actor?->name ?? 'System' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">No box events recorded.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('rightbar')
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Quick Actions</h5></div>
        <div class="card-body d-grid gap-2">
            <a href="{{ route('tech.storage.items.create') }}" class="btn btn-outline-primary">Add Item</a>
            <a href="{{ route('tech.storage.boxes.create') }}" class="btn btn-outline-secondary">New Box</a>
        </div>
    </div>
@endsection
