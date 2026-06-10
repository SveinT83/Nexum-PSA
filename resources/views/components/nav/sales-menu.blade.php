@php
    // Shared Sales workspace navigation for Sales and Commercial module views.
    $salesMenuItems = [
        [
            'label' => 'Sales',
            'route' => 'tech.sales.index',
            'pattern' => [
                'tech.sales.index',
                'tech.sales.create',
                'tech.sales.show',
                'tech.sales.update',
                'tech.sales.activities.*',
                'tech.sales.stakeholders.*',
                'tech.sales.quote.*',
            ],
            'icon' => 'bi-kanban',
        ],
        [
            'label' => 'Leads',
            'route' => 'tech.sales.leads.index',
            'pattern' => 'tech.sales.leads.*',
            'icon' => 'bi-person-plus',
        ],
        [
            'label' => 'Marketing',
            'route' => 'tech.marketing.index',
            'pattern' => 'tech.marketing.*',
            'icon' => 'bi-megaphone',
        ],
        [
            'label' => 'Contracts',
            'route' => 'tech.contracts.index',
            'pattern' => 'tech.contracts.*',
            'icon' => 'bi-file-earmark-text',
        ],
        [
            'label' => 'Packages',
            'route' => 'tech.packages.index',
            'pattern' => 'tech.packages.*',
            'icon' => 'bi-box-seam',
        ],
        [
            'label' => 'Services',
            'route' => 'tech.services.index',
            'pattern' => 'tech.services.*',
            'icon' => 'bi-layers',
        ],
        [
            'label' => 'Costs',
            'route' => 'tech.costs.index',
            'pattern' => 'tech.costs.*',
            'icon' => 'bi-cash-coin',
        ],
        [
            'label' => 'Legal & Terms',
            'route' => 'tech.legal.index',
            'pattern' => 'tech.legal.*',
            'icon' => 'bi-shield-lock',
        ],
        [
            'label' => 'Rates',
            'route' => 'tech.rates.index',
            'pattern' => 'tech.rates.*',
            'icon' => 'bi-currency-exchange',
        ],
        [
            'label' => 'SLA',
            'route' => 'tech.sla.index',
            'pattern' => 'tech.sla.*',
            'icon' => 'bi-stopwatch',
        ],
    ];
@endphp

<!-- ------------------------------------------------- -->
<!-- Sales Workspace Navigation -->
<!-- ------------------------------------------------- -->
<nav class="py-3" aria-label="Sales workspace navigation">
    <div class="px-2 mb-2">
        <div class="small text-uppercase fw-semibold text-muted">Sales workspace</div>
    </div>

    <div class="nav nav-pills flex-column gap-1">
        @foreach($salesMenuItems as $item)
            @continue(!Route::has($item['route']))

            @php
                $isActive = request()->routeIs(...(array) $item['pattern']);
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
