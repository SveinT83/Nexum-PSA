@extends('layouts.default_tech')

@section('title', 'Supplier Orders')

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="mb-0">Supplier Orders</h1>
        <x-buttons.back :url="route('tech.storage.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @php
            $activeFilterCount = collect([
                filled($filters['status'] ?? null),
                filled($filters['stage'] ?? null),
                filled($filters['vendor_id'] ?? null),
                filled($filters['warehouse_id'] ?? null),
                filled($filters['expected_after'] ?? null),
                filled($filters['expected_before'] ?? null),
                filled($filters['tracking_number'] ?? null),
                filled($filters['method'] ?? null),
            ])->filter()->count();
            $filtersOpen = $activeFilterCount > 0;
            $sort = $filters['sort'] ?? null;
            $direction = $filters['direction'] ?? 'asc';
            $sortQuery = collect($filters)
                ->reject(fn ($value, string $key): bool =>
                    $value === null
                    || $value === ''
                    || ($key === 'scope' && $value === 'all')
                    || ($key === 'per_page' && (int) $value === 25))
                ->all();
            $visibleScopes = collect($scopes)->filter(
                fn (string $label, string $scope): bool => match ($scope) {
                    'incoming' => $canViewImports,
                    'orders' => $canViewPurchaseOrders,
                    'receiving' => $canViewPurchaseOrders || $canReceive,
                    default => true,
                },
            );
            $statusClass = static fn (string $status): string => match ($status) {
                'ordered', 'processing' => 'text-bg-primary',
                'partially_received', 'needs_attention', 'retry_scheduled' => 'text-bg-warning',
                'received', 'closed', 'imported' => 'text-bg-success',
                'failed', 'rejected' => 'text-bg-danger',
                'cancelled', 'duplicate' => 'text-bg-secondary',
                default => 'text-bg-light border',
            };
        @endphp

        {{-- One scope bar replaces the three former procurement list pages. --}}
        <nav class="nav nav-pills flex-wrap gap-2 mb-3" aria-label="Supplier order views">
            @foreach($visibleScopes as $scope => $label)
                @php
                    $scopeQuery = collect($filters)
                        ->except(['page', 'scope'])
                        ->reject(fn ($value): bool => $value === null || $value === '')
                        ->all();
                    $scopeQuery['scope'] = $scope;
                    $scopeUrl = request()->url().'?'.http_build_query($scopeQuery, '', '&', PHP_QUERY_RFC3986);
                @endphp
                <a href="{{ $scopeUrl }}"
                   class="nav-link py-1 px-3 {{ ($filters['scope'] ?? 'all') === $scope ? 'active' : 'border bg-light text-body' }}"
                   @if(($filters['scope'] ?? 'all') === $scope) aria-current="page" @endif>
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        {{-- Search and filters apply to import, order, and receiving rows through one safe query. --}}
        <form method="GET" action="{{ request()->url() }}" class="card mb-3">
            <div class="card-body">
                <input type="hidden" name="scope" value="{{ $filters['scope'] ?? 'all' }}">
                <label for="supplier_order_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
                <div class="d-flex flex-column flex-lg-row gap-2">
                    <div class="input-group input-group-sm flex-grow-1">
                        <input
                            type="search"
                            id="supplier_order_search"
                            name="q"
                            class="form-control"
                            value="{{ $filters['q'] ?? '' }}"
                            placeholder="Order, import, supplier, reason, or tracking">
                        @if($sort)
                            <input type="hidden" name="sort" value="{{ $sort }}">
                            <input type="hidden" name="direction" value="{{ $direction }}">
                        @endif
                        <button type="submit" class="btn btn-outline-secondary">Search</button>
                        <button
                            class="btn btn-outline-secondary"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#supplierOrderFilters"
                            aria-expanded="{{ $filtersOpen ? 'true' : 'false' }}"
                            aria-controls="supplierOrderFilters"
                            title="Filters">
                            <i class="bi bi-funnel" aria-hidden="true"></i>
                            @if($activeFilterCount > 0)
                                <span class="badge text-bg-secondary ms-1">{{ $activeFilterCount }}</span>
                            @endif
                        </button>
                    </div>
                    @if($canRegisterPurchaseOrders)
                        <x-buttons.addlink :url="route('tech.storage.purchase-orders.create')" class="mb-0 text-nowrap">
                            Register Order
                        </x-buttons.addlink>
                    @endif
                </div>

                <div id="supplierOrderFilters" class="collapse {{ $filtersOpen ? 'show' : '' }} mt-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-6 col-xl-2">
                            <label for="status" class="form-label small text-muted mb-1">Status</label>
                            <select id="status" name="status" class="form-select form-select-sm">
                                <option value="">All statuses</option>
                                @foreach($statuses as $status)
                                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                                        {{ str($status)->replace('_', ' ')->title() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6 col-xl-3">
                            <label for="vendor_id" class="form-label small text-muted mb-1">Supplier</label>
                            <select id="vendor_id" name="vendor_id" class="form-select form-select-sm">
                                <option value="">All suppliers</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" @selected(($filters['vendor_id'] ?? '') == $supplier->id)>
                                        {{ $supplier->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        @if($canViewPurchaseOrders)
                            <div class="col-sm-6 col-xl-3">
                                <label for="warehouse_id" class="form-label small text-muted mb-1">Destination</label>
                                <select id="warehouse_id" name="warehouse_id" class="form-select form-select-sm">
                                    <option value="">All warehouses</option>
                                    @foreach($warehouses as $warehouse)
                                        <option value="{{ $warehouse->id }}" @selected(($filters['warehouse_id'] ?? '') == $warehouse->id)>
                                            {{ $warehouse->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6 col-xl-2">
                                <label for="expected_after" class="form-label small text-muted mb-1">Expected from</label>
                                <input type="date" id="expected_after" name="expected_after" class="form-control form-control-sm"
                                       value="{{ $filters['expected_after'] ?? '' }}">
                            </div>
                            <div class="col-sm-6 col-xl-2">
                                <label for="expected_before" class="form-label small text-muted mb-1">Expected by</label>
                                <input type="date" id="expected_before" name="expected_before" class="form-control form-control-sm"
                                       value="{{ $filters['expected_before'] ?? '' }}">
                            </div>
                            <div class="col-sm-6 col-xl-3">
                                <label for="tracking_number" class="form-label small text-muted mb-1">Tracking number</label>
                                <input type="search" id="tracking_number" name="tracking_number" class="form-control form-control-sm"
                                       value="{{ $filters['tracking_number'] ?? '' }}">
                            </div>
                        @endif
                        @if($canViewImports)
                            <div class="col-sm-6 col-xl-3">
                                <label for="stage" class="form-label small text-muted mb-1">Import stage</label>
                                <select id="stage" name="stage" class="form-select form-select-sm">
                                    <option value="">All stages</option>
                                    @foreach($stages as $stage)
                                        <option value="{{ $stage }}" @selected(($filters['stage'] ?? '') === $stage)>
                                            {{ str($stage)->replace('_', ' ')->title() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6 col-xl-3">
                                <label for="method" class="form-label small text-muted mb-1">Extraction method</label>
                                <input type="text" id="method" name="method" maxlength="255" class="form-control form-control-sm"
                                       value="{{ $filters['method'] ?? '' }}">
                            </div>
                        @endif
                        <div class="col-sm-6 col-xl-2">
                            <label for="per_page" class="form-label small text-muted mb-1">Rows</label>
                            <select id="per_page" name="per_page" class="form-select form-select-sm">
                                @foreach([25, 50, 100] as $size)
                                    <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 25) === $size)>{{ $size }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6 col-xl-2 d-grid">
                            <button type="submit" class="btn btn-sm btn-secondary">Apply filters</button>
                        </div>
                        <div class="col-sm-6 col-xl-2 d-grid">
                            <a href="{{ request()->url() }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        {{-- Every supplier order appears once: an incoming import or its canonical Purchase Order. --}}
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-3">
                <div>
                    <h2 class="h6 mb-0">Supplier Order Workflow</h2>
                    <div class="small text-muted">{{ $supplierOrders->total() }} rows in this view</div>
                </div>
                <span class="badge text-bg-light border">Imports never update stock</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <x-tables.sortable-header label="Order" column="order" :current-sort="$sort"
                                                  :current-direction="$direction" :query="$sortQuery" />
                        <x-tables.sortable-header label="Supplier" column="supplier" :current-sort="$sort"
                                                  :current-direction="$direction" :query="$sortQuery" />
                        <x-tables.sortable-header label="Status" column="status" :current-sort="$sort"
                                                  :current-direction="$direction" :query="$sortQuery" />
                        <x-tables.sortable-header label="Expected / activity" column="expected" :current-sort="$sort"
                                                  :current-direction="$direction" :query="$sortQuery" />
                        <x-tables.sortable-header label="Progress" column="progress" :current-sort="$sort"
                                                  :current-direction="$direction" :query="$sortQuery" />
                        <x-tables.sortable-header label="Outstanding" column="outstanding" :current-sort="$sort"
                                                  :current-direction="$direction" :query="$sortQuery" align="end" />
                        <th class="text-end">Next step</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($supplierOrders as $row)
                        @php
                            $isPurchaseOrder = $row->row_type === 'purchase_order';
                            $ordered = (int) $row->qty_ordered;
                            $received = (int) $row->qty_received;
                            $cancelled = (int) $row->qty_cancelled;
                            $outstanding = (int) $row->qty_outstanding;
                            $progress = $ordered > 0 ? min(100, (int) round(($received / $ordered) * 100)) : 0;
                            $expectedAt = filled($row->expected_at)
                                ? \Illuminate\Support\Carbon::parse($row->expected_at)
                                : null;
                            $updatedAt = filled($row->updated_at)
                                ? \Illuminate\Support\Carbon::parse($row->updated_at)
                                : null;
                            $metadata = is_string($row->purchase_order_metadata)
                                ? (json_decode($row->purchase_order_metadata, true) ?: [])
                                : (array) ($row->purchase_order_metadata ?? []);
                            $createdFromEmail = data_get($metadata, 'created_from') === 'supplier_order_email_import';
                            [$provenanceIcon, $provenanceLabel, $provenanceClass] = match (true) {
                                ! $isPurchaseOrder => ['bi-inbox', 'Incoming supplier-order import', 'text-primary'],
                                $createdFromEmail => ['bi-envelope', 'Created from supplier email', 'text-primary'],
                                $row->import_id !== null => ['bi-envelope-check', 'Manually registered; confirmed by supplier email', 'text-success'],
                                default => ['bi-person', 'Registered manually', 'text-muted'],
                            };
                        @endphp
                        <tr>
                            <td>
                                <div class="d-flex align-items-start gap-2">
                                    <span class="d-inline-flex flex-shrink-0 mt-1 {{ $provenanceClass }}" title="{{ $provenanceLabel }}">
                                        <i class="bi {{ $provenanceIcon }}" aria-hidden="true"></i>
                                        <span class="visually-hidden">{{ $provenanceLabel }}: </span>
                                    </span>
                                    <div>
                                        @if($isPurchaseOrder && $canViewPurchaseOrders)
                                            <a href="{{ route('tech.storage.purchase-orders.show', $row->purchase_order_id) }}"
                                               class="fw-semibold text-decoration-none">
                                                {{ $row->order_number }}
                                            </a>
                                        @elseif($isPurchaseOrder)
                                            <span class="fw-semibold">{{ $row->order_number }}</span>
                                        @else
                                            <a href="{{ route('tech.storage.purchase-order-imports.show', $row->import_id) }}"
                                               class="fw-semibold text-decoration-none">
                                                Import #{{ $row->import_id }}
                                            </a>
                                        @endif
                                        <div class="small text-muted">
                                            {{ $row->supplier_order_number ?: ($isPurchaseOrder ? 'No supplier order number' : 'Order number not extracted') }}
                                        </div>
                                        @if($isPurchaseOrder && $row->import_id && $canViewImports)
                                            <div class="small">
                                                <a href="{{ route('tech.storage.purchase-order-imports.show', $row->import_id) }}">
                                                    Import #{{ $row->import_id }}
                                                </a>
                                                @if((int) $row->linked_import_count > 1)
                                                    <span class="text-muted">+ {{ (int) $row->linked_import_count - 1 }} related</span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span>{{ $row->supplier_name ?: 'Unresolved supplier' }}</span>
                                <div class="small text-muted">
                                    {{ $isPurchaseOrder ? ($row->destination_name ?: 'Unknown destination') : ($row->profile_name ?: 'No supplier profile') }}
                                </div>
                            </td>
                            <td>
                                <span class="badge {{ $statusClass((string) $row->status) }}">
                                    {{ str($row->status)->replace('_', ' ')->title() }}
                                </span>
                                <div class="small text-muted mt-1">
                                    {{ str($row->stage)->replace('_', ' ')->title() }}{{ $isPurchaseOrder ? '' : ' import stage' }}
                                </div>
                                @if($row->reason_code)
                                    <div class="small text-danger">{{ $row->reason_code }}</div>
                                @endif
                            </td>
                            <td>
                                @if($isPurchaseOrder)
                                    <span>{{ $expectedAt?->format('d.m.Y') ?: '-' }}</span>
                                    @if($expectedAt && $outstanding > 0 && $expectedAt->isPast())
                                        <div class="small text-danger">Overdue</div>
                                    @endif
                                @else
                                    <span>{{ $updatedAt?->format('d.m.Y H:i') ?: '-' }}</span>
                                    @if($row->next_retry_at)
                                        <div class="small text-muted">Retry {{ \Illuminate\Support\Carbon::parse($row->next_retry_at)->format('d.m H:i') }}</div>
                                    @endif
                                @endif
                            </td>
                            <td style="min-width: 10rem;">
                                @if($isPurchaseOrder)
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span>{{ $received }} / {{ $ordered }}</span>
                                        <span>{{ $progress }}%</span>
                                    </div>
                                    <div class="progress" role="progressbar" aria-label="Received progress"
                                         aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100"
                                         style="height: .35rem;">
                                        <div class="progress-bar" style="width: {{ $progress }}%"></div>
                                    </div>
                                    @if($cancelled > 0)
                                        <div class="small text-muted mt-1">{{ $cancelled }} cancelled</div>
                                    @endif
                                @else
                                    <span>{{ str($row->import_method ?: 'Awaiting extraction')->replace('_', ' ')->title() }}</span>
                                    <div class="small text-muted">{{ (int) $row->attempt_count }} attempts</div>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">{{ $isPurchaseOrder ? $outstanding : '-' }}</td>
                            <td class="text-end">
                                <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                    @if(! $isPurchaseOrder)
                                        <a href="{{ route('tech.storage.purchase-order-imports.show', $row->import_id) }}"
                                           class="btn btn-sm btn-outline-primary">Review</a>
                                    @else
                                        @if($canViewPurchaseOrders)
                                            <a href="{{ route('tech.storage.purchase-orders.show', $row->purchase_order_id) }}"
                                               class="btn btn-sm btn-outline-secondary">Open</a>
                                        @endif
                                        @if((bool) $row->is_receivable && $canViewPurchaseOrders)
                                            <a href="{{ route('tech.storage.purchase-orders.control-slip', $row->purchase_order_id) }}"
                                               class="btn btn-sm btn-outline-secondary" title="Printable control slip">
                                                <i class="bi bi-printer" aria-hidden="true"></i>
                                                <span class="visually-hidden">Control slip</span>
                                            </a>
                                        @endif
                                        @if((bool) $row->is_receivable && $canReceive)
                                            <a href="{{ route('tech.storage.purchase-orders.receive', $row->purchase_order_id) }}"
                                               class="btn btn-sm btn-success">Receive</a>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                No supplier orders match this view.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($supplierOrders->hasPages())
                <div class="card-footer">{{ $supplierOrders->links() }}</div>
            @endif
        </div>
    </div>
@endsection

@section('rightbar')
    <div class="accordion mb-3" id="supplierOrderHelpAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="supplierOrderHelpHeader">
                <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse"
                        data-bs-target="#supplierOrderHelp" aria-expanded="false" aria-controls="supplierOrderHelp">
                    One supplier-order workflow
                </button>
            </h2>
            <div id="supplierOrderHelp" class="accordion-collapse collapse"
                 aria-labelledby="supplierOrderHelpHeader" data-bs-parent="#supplierOrderHelpAccordion">
                <div class="accordion-body small">
                    Incoming confirmations, registered orders, and goods awaiting receipt share this list. An import
                    never updates stock; inventory changes only after an authorized receipt is posted.
                </div>
            </div>
        </div>
    </div>
@endsection
