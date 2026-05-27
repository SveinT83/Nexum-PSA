@extends('layouts.default_tech')

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
            @if($item->needs_reorder)
                <span class="badge text-bg-warning">Should order</span>
            @else
                <span class="badge text-bg-success">Stock OK</span>
            @endif
            <a href="{{ route('tech.storage.items.edit', $item) }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-pencil" aria-hidden="true"></i>
                Edit
            </a>
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
                <form method="POST" action="{{ route('tech.storage.items.adjust', $item) }}" class="row g-3 align-items-end">
                    @csrf
                    <div class="col-md-3">
                        <label for="delta" class="form-label">Delta</label>
                        <input type="number" id="delta" name="delta" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label for="reason" class="form-label">Reason</label>
                        <select id="reason" name="reason" class="form-select" required>
                            <option value="inventory_correction">Inventory correction</option>
                            <option value="damage">Damage</option>
                            <option value="shrink">Shrink</option>
                            <option value="manual_intake">Manual intake</option>
                            <option value="manual_withdrawal">Manual withdrawal</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="note" class="form-label">Note</label>
                        <input type="text" id="note" name="note" class="form-control">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-primary">Apply</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">Movement History</h5></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th>When</th>
                        <th>Type</th>
                        <th class="text-end">Before</th>
                        <th class="text-end">Delta</th>
                        <th class="text-end">After</th>
                        <th>Reason</th>
                        <th>Actor</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($item->movements->sortByDesc('created_at') as $movement)
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
                <dt class="col-6">Serials</dt>
                <dd class="col-6 text-end">{{ $item->has_serials ? 'Yes' : 'No' }}</dd>
            </dl>
        </div>
    </div>
@endsection
