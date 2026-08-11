@php
    // Supplier-order intake, Purchase Orders, and receiving share one operational work queue.
    $canViewPurchaseOrders = auth()->user()?->can('storage.purchase_view') ?? false;
    $canViewSupplierImports = auth()->user()?->can('storage.purchase_import_view') ?? false;
    $canReceivePurchases = auth()->user()?->can('storage.purchase_receive') ?? false;
    $supplierOrdersRoute = match (true) {
        $canViewPurchaseOrders => 'tech.storage.purchase-orders.index',
        $canViewSupplierImports => 'tech.storage.purchase-order-imports.index',
        $canReceivePurchases => 'tech.storage.receiving.index',
        default => 'tech.storage.purchase-orders.index',
    };

    $storageMenuItems = [
        [
            'label' => 'Inventory',
            'route' => 'tech.storage.index',
            'pattern' => 'tech.storage.index',
            'icon' => 'bi-box-seam',
            'permission' => 'storage.view',
        ],
        [
            'label' => 'Supplier Orders',
            'route' => $supplierOrdersRoute,
            'patterns' => [
                'tech.storage.purchase-orders.*',
                'tech.storage.purchase-order-imports.*',
                'tech.storage.receiving.*',
                'tech.storage.receipts.*',
            ],
            'icon' => 'bi-receipt',
            'permissions' => [
                'storage.purchase_view',
                'storage.purchase_import_view',
                'storage.purchase_receive',
            ],
        ],
        [
            'label' => 'Picking List',
            'route' => 'tech.storage.picking',
            'pattern' => 'tech.storage.picking*',
            'icon' => 'bi-check2-square',
            'permission' => 'storage.pick',
        ],
    ];
@endphp

<!-- ------------------------------------------------- -->
<!-- Storage Workspace Navigation -->
<!-- ------------------------------------------------- -->
<nav class="py-3" aria-label="Storage workspace navigation">
    <div class="px-2 mb-2">
        <div class="small text-uppercase fw-semibold text-muted">Storage workspace</div>
    </div>

    <div class="nav nav-pills flex-column gap-1">
        @foreach($storageMenuItems as $item)
            @php
                $permissions = $item['permissions'] ?? [$item['permission']];
                $hasPermission = collect($permissions)
                    ->contains(fn (string $permission): bool => auth()->user()?->can($permission) ?? false);
            @endphp
            @continue(! $hasPermission)
            @continue(! Route::has($item['route']))

            @php
                $isActive = request()->routeIs(...($item['patterns'] ?? [$item['pattern']]));
            @endphp

            <a
                href="{{ route($item['route']) }}"
                class="nav-link d-flex align-items-center gap-2 px-3 py-2 {{ $isActive ? 'active' : 'link-dark bg-light border' }}"
                @if($isActive) aria-current="page" @endif>
                <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </div>
</nav>
