@php
    // Shared Economy workspace navigation for internal order and billing preparation views.
    $economyMenuItems = [
        [
            'label' => 'Orders',
            'route' => 'tech.economy.orders.index',
            'pattern' => 'tech.economy.orders.*',
            'icon' => 'bi-receipt',
        ],
        [
            'label' => 'Settings',
            'route' => 'tech.economy.settings',
            'pattern' => 'tech.economy.settings',
            'icon' => 'bi-sliders',
        ],
    ];
@endphp

<!-- ------------------------------------------------- -->
<!-- Economy Workspace Navigation -->
<!-- ------------------------------------------------- -->
<nav class="py-3" aria-label="Economy workspace navigation">
    <div class="px-2 mb-2">
        <div class="small text-uppercase fw-semibold text-muted">Economy workspace</div>
    </div>

    <div class="nav nav-pills flex-column gap-1">
        @foreach($economyMenuItems as $item)
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
