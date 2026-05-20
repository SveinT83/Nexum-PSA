@php
    // Shared Storage workspace navigation for inventory and ticket picking operations.
    $storageMenuItems = [
        [
            'label' => 'Inventory',
            'route' => 'tech.storage.index',
            'pattern' => 'tech.storage.index',
            'icon' => 'bi-box-seam',
        ],
        [
            'label' => 'Picking List',
            'route' => 'tech.storage.picking',
            'pattern' => 'tech.storage.picking*',
            'icon' => 'bi-check2-square',
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
            @continue(!Route::has($item['route']))

            @php
                $isActive = request()->routeIs($item['pattern']);
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
