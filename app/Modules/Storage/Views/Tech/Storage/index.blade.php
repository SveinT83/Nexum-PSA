@extends('layouts.default_tech')

@section('title', 'Storage')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">Storage</h1>
            <p class="text-muted mb-0">Inventory, boxes, warehouses, and stock status.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('tech.storage.items.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> New Item
            </a>
            <a href="{{ route('tech.storage.boxes.create') }}" class="btn btn-outline-primary">
                <i class="bi bi-box"></i> New Box
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        <form method="GET" action="{{ route('tech.storage.index') }}" class="card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="q" class="form-label">Search</label>
                        <input type="search" id="q" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}"
                               placeholder="SKU, name, or EAN">
                    </div>
                    <div class="col-md-3">
                        <label for="warehouse_id" class="form-label">Warehouse</label>
                        <select id="warehouse_id" name="warehouse_id" class="form-select">
                            <option value="">All warehouses</option>
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected(($filters['warehouse_id'] ?? '') == $warehouse->id)>
                                    {{ $warehouse->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="availability" class="form-label">Availability</label>
                        <select id="availability" name="availability" class="form-select">
                            <option value="should_order" @selected(($filters['availability'] ?? 'should_order') === 'should_order')>Should order</option>
                            <option value="all" @selected(($filters['availability'] ?? '') === 'all')>All</option>
                            <option value="in_stock" @selected(($filters['availability'] ?? '') === 'in_stock')>In stock</option>
                            <option value="out_of_stock" @selected(($filters['availability'] ?? '') === 'out_of_stock')>Out of stock</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-outline-primary">Apply</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Item</th>
                        <th>Warehouse</th>
                        <th>Box</th>
                        <th class="text-end">On-hand</th>
                        <th class="text-end">Reserved</th>
                        <th class="text-end">Available</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($items as $item)
                        <tr>
                            <td>
                                <a href="{{ route('tech.storage.items.show', $item) }}" class="fw-semibold text-decoration-none">
                                    {{ $item->sku }}
                                </a>
                                <div class="text-muted small">{{ $item->name }}</div>
                            </td>
                            <td>{{ $item->warehouse->name }}</td>
                            <td>
                                @if($item->box)
                                    <a href="{{ route('tech.storage.boxes.show', $item->box) }}">{{ $item->box->code_human ?: 'Box #' . $item->box->id }}</a>
                                @else
                                    <span class="text-muted">Unboxed</span>
                                @endif
                            </td>
                            <td class="text-end">{{ $item->qty_on_hand }}</td>
                            <td class="text-end">{{ $item->qty_reserved }}</td>
                            <td class="text-end">{{ $item->qty_available }}</td>
                            <td>
                                @if($item->needs_reorder)
                                    <span class="badge text-bg-warning">Should order</span>
                                @else
                                    <span class="badge text-bg-success">OK</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('tech.storage.items.show', $item) }}" class="btn btn-sm btn-outline-primary">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                No storage items match this view.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($items->hasPages())
                <div class="card-footer">
                    {{ $items->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection

@section('rightbar')
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Quick Stats</h5>
        </div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-8">Items</dt>
                <dd class="col-4 text-end">{{ $stats['total_items'] }}</dd>
                <dt class="col-8">Out of stock</dt>
                <dd class="col-4 text-end">{{ $stats['out_of_stock'] }}</dd>
                <dt class="col-8">Should order</dt>
                <dd class="col-4 text-end">{{ $stats['should_order'] }}</dd>
                <dt class="col-8">Reserved</dt>
                <dd class="col-4 text-end">{{ $stats['reserved'] }}</dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Add Warehouse</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('tech.storage.warehouses.store') }}">
                @csrf
                <div class="mb-3">
                    <label for="warehouse_name" class="form-label">Name</label>
                    <input type="text" id="warehouse_name" name="name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="warehouse_code" class="form-label">Code</label>
                    <input type="text" id="warehouse_code" name="code" class="form-control" placeholder="MAIN">
                </div>
                <button type="submit" class="btn btn-outline-primary w-100">Create Warehouse</button>
            </form>
        </div>
    </div>
@endsection
