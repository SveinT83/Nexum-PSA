@extends('layouts.default_tech')

@section('title', 'Storage')

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    @php
        $showWarehouseModal = old('_warehouse_form') === '1'
            || $errors->has('name')
            || $errors->has('code')
            || $errors->has('address')
            || $errors->has('notes');
    @endphp

    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">Storage</h1>
            <p class="text-muted mb-0">Inventory, boxes, warehouses, and stock status.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#storageAddWarehouseModal">
                <i class="bi bi-building"></i> Add Warehouse
            </button>
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
        {{-- Storage filters keep stock triage close to the inventory list. --}}
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

        {{-- Inventory list is the primary working surface for warehouse and box stock. --}}
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

    {{-- Warehouse creation is a secondary page action opened from the page header. --}}
    <div class="modal fade" id="storageAddWarehouseModal" tabindex="-1" aria-labelledby="storageAddWarehouseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route('tech.storage.warehouses.store') }}">
                    @csrf
                    <input type="hidden" name="_warehouse_form" value="1">

                    <div class="modal-header">
                        <h5 class="modal-title" id="storageAddWarehouseModalLabel">Add Warehouse</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="warehouse_name" class="form-label">Name</label>
                            <input type="text" id="warehouse_name" name="name" class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name') }}" required autofocus>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="warehouse_code" class="form-label">Code</label>
                            <input type="text" id="warehouse_code" name="code" class="form-control @error('code') is-invalid @enderror"
                                   value="{{ old('code') }}" placeholder="MAIN">
                            @error('code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="warehouse_address" class="form-label">Address</label>
                            <textarea id="warehouse_address" name="address" rows="2" class="form-control @error('address') is-invalid @enderror">{{ old('address') }}</textarea>
                            @error('address')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="warehouse_notes" class="form-label">Notes</label>
                            <textarea id="warehouse_notes" name="notes" rows="3" class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                            @error('notes')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Warehouse</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @if($showWarehouseModal)
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const modalElement = document.getElementById('storageAddWarehouseModal');

                if (modalElement && window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
                }
            });
        </script>
    @endif
@endsection

@section('rightbar')
    {{-- Operational stock summary remains in the right sidebar; creation actions live in the page header. --}}
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
@endsection
