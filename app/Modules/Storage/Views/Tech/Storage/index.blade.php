@extends('layouts.default_tech')

@section('title', 'Storage')

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="mb-0">Storage</h1>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @php
            $storageActiveFilterCount = collect([
                filled($filters['warehouse_id'] ?? null),
                filled($filters['supplier_id'] ?? null),
                ($filters['availability'] ?? 'should_order') !== 'should_order',
            ])->filter()->count();
            $storageFiltersOpen = $storageActiveFilterCount > 0;
        @endphp

        {{-- Storage filters keep stock triage close to the inventory list. --}}
        <form method="GET" action="{{ route('tech.storage.index') }}" class="card mb-3">
            <div class="card-body">
                <label for="storage_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
                <div class="input-group input-group-sm">
                    <input type="search" id="storage_search" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}"
                           placeholder="SKU, name, or EAN">
                    <button type="submit" class="btn btn-outline-secondary">Search</button>
                    <button
                        class="btn btn-outline-secondary"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#storageFiltersCollapse"
                        aria-expanded="{{ $storageFiltersOpen ? 'true' : 'false' }}"
                        aria-controls="storageFiltersCollapse"
                        title="Filters">
                        <i class="bi bi-funnel" aria-hidden="true"></i>
                        @if($storageActiveFilterCount > 0)
                            <span class="badge text-bg-secondary ms-1">{{ $storageActiveFilterCount }}</span>
                        @endif
                    </button>
                </div>

                <div id="storageFiltersCollapse" class="collapse {{ $storageFiltersOpen ? 'show' : '' }} mt-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label for="warehouse_id" class="form-label small text-muted mb-1">Warehouse</label>
                            <select id="warehouse_id" name="warehouse_id" class="form-select form-select-sm">
                                <option value="">All warehouses</option>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected(($filters['warehouse_id'] ?? '') == $warehouse->id)>
                                        {{ $warehouse->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="supplier_id" class="form-label small text-muted mb-1">Supplier</label>
                            <select id="supplier_id" name="supplier_id" class="form-select form-select-sm">
                                <option value="">All suppliers</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" @selected(($filters['supplier_id'] ?? '') == $supplier->id)>
                                        {{ $supplier->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="availability" class="form-label small text-muted mb-1">Availability</label>
                            <select id="availability" name="availability" class="form-select form-select-sm">
                                <option value="should_order" @selected(($filters['availability'] ?? 'should_order') === 'should_order')>Should order</option>
                                <option value="all" @selected(($filters['availability'] ?? '') === 'all')>All</option>
                                <option value="in_stock" @selected(($filters['availability'] ?? '') === 'in_stock')>In stock</option>
                                <option value="out_of_stock" @selected(($filters['availability'] ?? '') === 'out_of_stock')>Out of stock</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" class="btn btn-sm btn-secondary">Apply filters</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        {{-- Inventory list is the primary working surface for warehouse and box stock. --}}
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-3">
                <div>
                    <h2 class="h6 mb-0">Inventory Items</h2>
                    <div class="small text-muted">{{ $items->total() }} items in this view</div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <x-buttons.addlink :url="route('tech.storage.items.create')" class="mb-0">
                        New Item
                    </x-buttons.addlink>
                    <x-buttons.addlink :url="route('tech.storage.boxes.create')" class="mb-0">
                        New Box
                    </x-buttons.addlink>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Item</th>
                        <th>Warehouse</th>
                        <th>Supplier</th>
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
                                @if($item->primaryVendor)
                                    <a href="{{ route('tech.documentations.vendors.show', $item->primaryVendor) }}">{{ $item->primaryVendor->name }}</a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
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
                            <td colspan="9" class="text-center text-muted py-5">
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
    @include('storage::Tech.Storage.items.partials.documentation-card')

    {{-- Operational stock summary remains in the right sidebar while list actions live in the inventory card. --}}
    <div class="accordion mb-3" id="storageQuickStatsAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="storageQuickStatsHeader">
                <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#storageQuickStatsCollapse" aria-expanded="false" aria-controls="storageQuickStatsCollapse">
                    <span>Quick Stats</span>
                    @if(($stats['should_order'] ?? 0) > 0)
                        <span class="badge text-bg-warning ms-2">{{ $stats['should_order'] }} should order</span>
                    @endif
                </button>
            </h2>
            <div id="storageQuickStatsCollapse" class="accordion-collapse collapse" aria-labelledby="storageQuickStatsHeader" data-bs-parent="#storageQuickStatsAccordion">
                <div class="accordion-body">
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="border rounded p-2 h-100">
                                <div class="small text-muted">Items</div>
                                <div class="fw-semibold">{{ $stats['total_items'] }}</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2 h-100">
                                <div class="small text-muted">Out of stock</div>
                                <div class="fw-semibold">{{ $stats['out_of_stock'] }}</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2 h-100">
                                <div class="small text-muted">Should order</div>
                                <div class="fw-semibold">{{ $stats['should_order'] }}</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2 h-100">
                                <div class="small text-muted">Reserved</div>
                                <div class="fw-semibold">{{ $stats['reserved'] }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
