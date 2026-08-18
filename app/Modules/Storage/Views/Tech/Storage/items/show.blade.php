@extends('layouts.default_tech')

@php
    $canUseGenericAdjustment = $canUseGenericAdjustment ?? (
        ! $item->has_serials
        && ! $item->track_batch
        && ! $item->expiry_enabled
        && ! $item->stockUnits()->where('current_qty', '>', 0)->exists()
    );
@endphp

@section('title', $item->sku . ' - Storage Item')

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.storage.index') }}">Storage</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $item->sku }}</li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-start gap-3">
        <div>
            <h1 class="mb-1">{{ $item->sku }} - {{ $item->name }}</h1>
            <div class="text-muted">
                {{ $item->warehouse->name }}
                @if($item->box)
                    / {{ $item->box->code_human ?: 'Box #' . $item->box->id }}
                @endif
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            @php($canDeleteItem = $item->canBeDeletedFromInventory())
            @if($item->needs_reorder)
                <span class="badge text-bg-warning">Should order</span>
            @else
                <span class="badge text-bg-success">Stock OK</span>
            @endif
            <a href="{{ route('tech.storage.items.edit', $item) }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-pencil" aria-hidden="true"></i>
                Edit
            </a>
            @if($canDeleteItem)
                <form method="POST" action="{{ route('tech.storage.items.destroy', $item) }}" onsubmit="return confirm('Delete this storage item? Historical references remain, but the item will be hidden from inventory.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                        Delete
                    </button>
                </form>
            @else
                <button type="button" class="btn btn-sm btn-outline-danger" disabled title="On-hand, reserved, and stock unit quantities must be 0.">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                    Delete
                </button>
            @endif
        </div>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted small">On-hand</div>
                        <div class="display-6">{{ $item->qty_on_hand }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted small">Reserved</div>
                        <div class="display-6">{{ $item->qty_reserved }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted small">Available</div>
                        <div class="display-6">{{ $item->qty_available }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Adjust Stock</h5></div>
            <div class="card-body">
                @if($canUseGenericAdjustment)
                <form method="POST" action="{{ route('tech.storage.items.adjust', $item) }}" class="row g-3 align-items-end">
                    @csrf
                    <div class="col-md-3">
                        <label for="adjustment_mode" class="form-label">Adjustment</label>
                        <select id="adjustment_mode" name="adjustment_mode" class="form-select" required>
                            <option value="set" @selected(old('adjustment_mode', 'set') === 'set')>Set on-hand to</option>
                            <option value="increase" @selected(old('adjustment_mode') === 'increase')>Increase by</option>
                            <option value="decrease" @selected(old('adjustment_mode') === 'decrease')>Decrease by</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="quantity" class="form-label">Quantity</label>
                        <input type="number" id="quantity" name="quantity" class="form-control @error('quantity') is-invalid @enderror" min="0" value="{{ old('quantity', $item->qty_on_hand) }}" required>
                        @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <label for="reason" class="form-label">Reason</label>
                        <select id="reason" name="reason" class="form-select" required>
                            <option value="inventory_correction">Inventory correction</option>
                            <option value="damage">Damage</option>
                            <option value="shrink">Shrink</option>
                            <option value="manual_intake">Manual intake</option>
                            <option value="manual_withdrawal">Manual withdrawal</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="note" class="form-label">Note</label>
                        <input type="text" id="note" name="note" class="form-control">
                    </div>
                    <div class="col-md-1 d-grid">
                        <button type="submit" class="btn btn-primary">Apply</button>
                    </div>
                </form>
                @else
                    <div class="alert alert-light border mb-0" role="note">
                        <div class="fw-semibold mb-1">Identified stock cannot use generic quantity adjustment.</div>
                        <div class="small text-muted">
                            This item is tracked by serial, batch, expiry, or positive StockUnit records. Correct a posted
                            goods receipt with its reversal action. Other corrections require an identified-unit workflow
                            so the item balance and unit ledger remain synchronized.
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card" id="movement-history">
            <div class="card-header"><h5 class="mb-0">Movement History</h5></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <x-tables.sortable-header label="When" column="when"
                                                  :current-sort="$movementSort" :current-direction="$movementDirection"
                                                  :query="$movementSortQuery" sort-parameter="movement_sort"
                                                  direction-parameter="movement_direction" fragment="movement-history"
                                                  default-direction="desc" />
                        <x-tables.sortable-header label="Type" column="type"
                                                  :current-sort="$movementSort" :current-direction="$movementDirection"
                                                  :query="$movementSortQuery" sort-parameter="movement_sort"
                                                  direction-parameter="movement_direction" fragment="movement-history" />
                        <x-tables.sortable-header label="Before" column="before" align="end"
                                                  :current-sort="$movementSort" :current-direction="$movementDirection"
                                                  :query="$movementSortQuery" sort-parameter="movement_sort"
                                                  direction-parameter="movement_direction" fragment="movement-history" />
                        <x-tables.sortable-header label="Delta" column="delta" align="end"
                                                  :current-sort="$movementSort" :current-direction="$movementDirection"
                                                  :query="$movementSortQuery" sort-parameter="movement_sort"
                                                  direction-parameter="movement_direction" fragment="movement-history" />
                        <x-tables.sortable-header label="After" column="after" align="end"
                                                  :current-sort="$movementSort" :current-direction="$movementDirection"
                                                  :query="$movementSortQuery" sort-parameter="movement_sort"
                                                  direction-parameter="movement_direction" fragment="movement-history" />
                        <x-tables.sortable-header label="Reason" column="reason"
                                                  :current-sort="$movementSort" :current-direction="$movementDirection"
                                                  :query="$movementSortQuery" sort-parameter="movement_sort"
                                                  direction-parameter="movement_direction" fragment="movement-history" />
                        <x-tables.sortable-header label="Actor" column="actor"
                                                  :current-sort="$movementSort" :current-direction="$movementDirection"
                                                  :query="$movementSortQuery" sort-parameter="movement_sort"
                                                  direction-parameter="movement_direction" fragment="movement-history" />
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($movements as $movement)
                        <tr>
                            <td>{{ $movement->created_at->format('Y-m-d H:i') }}</td>
                            <td>{{ str_replace('_', ' ', ucfirst($movement->type)) }}</td>
                            <td class="text-end">{{ $movement->qty_before }}</td>
                            <td class="text-end">{{ $movement->qty_delta }}</td>
                            <td class="text-end">{{ $movement->qty_after }}</td>
                            <td>{{ $movement->reason }}</td>
                            <td>{{ $movement->actor?->name ?? 'System' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No movements recorded.</td>
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
        <div class="card-header"><h5 class="mb-0">Quick Facts</h5></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-6">EAN</dt>
                <dd class="col-6 text-end">{{ $item->ean_number ?: '-' }}</dd>
                <dt class="col-6">Vendor</dt>
                <dd class="col-6 text-end">
                    @if($item->manufacturerVendor)
                        <a href="{{ route('tech.documentations.vendors.show', $item->manufacturerVendor) }}">{{ $item->manufacturerVendor->name }}</a>
                    @else
                        {{ $item->manufacturer ?: '—' }}
                    @endif
                </dd>
                <dt class="col-6">Supplier</dt>
                <dd class="col-6 text-end">
                    @if($item->primaryVendor)
                        <a href="{{ route('tech.documentations.vendors.show', $item->primaryVendor) }}">{{ $item->primaryVendor->name }}</a>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </dd>
                <dt class="col-6">Reorder</dt>
                <dd class="col-6 text-end">{{ $item->reorder_point }}</dd>
                <dt class="col-6">Target</dt>
                <dd class="col-6 text-end">{{ $item->target_level }}</dd>
                <dt class="col-6">Suggested</dt>
                <dd class="col-6 text-end">{{ $item->suggested_order_qty }}</dd>
                <dt class="col-6">Orderable</dt>
                <dd class="col-6 text-end">{{ $item->can_be_ordered ? 'Yes' : 'No' }}</dd>
                <dt class="col-6">Quote required</dt>
                <dd class="col-6 text-end">{{ $item->requires_customer_quote ? 'Yes' : 'No' }}</dd>
                <dt class="col-6">Serials</dt>
                <dd class="col-6 text-end">{{ $item->has_serials ? 'Yes' : 'No' }}</dd>
                <dt class="col-6">Batch tracking</dt>
                <dd class="col-6 text-end">{{ $item->track_batch ? 'Yes' : 'No' }}</dd>
                <dt class="col-6">Expiry tracking</dt>
                <dd class="col-6 text-end">{{ $item->expiry_enabled ? 'Yes' : 'No' }}</dd>
            </dl>
        </div>
    </div>
@endsection
