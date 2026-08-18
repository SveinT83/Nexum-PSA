@php
    // Shared Work workspace navigation across modules that own operational work views.
    $workMenuItems = [
        [
            'label' => 'Risk',
            'route' => 'tech.risk.index',
            'pattern' => 'tech.risk*',
            'icon' => 'bi-shield-exclamation',
        ],
        [
            'label' => 'Mail',
            'route' => 'tech.mail.index',
            'pattern' => 'tech.mail*',
            'icon' => 'bi-inbox',
        ],
        [
            'label' => 'Tasks',
            'route' => 'tech.tasks.index',
            'pattern' => 'tech.tasks*',
            'icon' => 'bi-list-task',
        ],
        [
            'label' => 'Tickets',
            'route' => 'tech.tickets.index',
            'pattern' => 'tech.tickets*',
            'icon' => 'bi-ticket-detailed',
        ],
        [
            'label' => 'Telephony',
            'route' => 'tech.telephony.profile',
            'pattern' => 'tech.telephony*',
            'icon' => 'bi-telephone',
        ],
        [
            'label' => 'Assets',
            'route' => 'tech.assets.index',
            'pattern' => 'tech.assets*',
            'icon' => 'bi-pc-display',
        ],
        [
            'label' => 'Calendar',
            'route' => 'tech.calendar.index',
            'pattern' => 'tech.calendar*',
            'icon' => 'bi-calendar-week',
        ],
    ];
@endphp

<!-- ------------------------------------------------- -->
<!-- Work Workspace Navigation -->
<!-- ------------------------------------------------- -->
<nav class="py-3" aria-label="Work workspace navigation">
    <div class="px-2 mb-2">
        <div class="small text-uppercase fw-semibold text-muted">Work workspace</div>
    </div>

    <div class="nav nav-pills flex-column gap-1">
        @foreach($workMenuItems as $item)
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
