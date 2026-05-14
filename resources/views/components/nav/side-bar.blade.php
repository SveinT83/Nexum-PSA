<!-- ------------------------------------------------- -->
<!-- Side Bar Menu -->
<!-- ------------------------------------------------- -->

<!--
    Use theis array in the active controller in order to use the side-bar menu

    $sidebarMenuItems = [
        ['name' => 'Dashboard', 'route' => 'tech.admin.settings.economy'],
        ['name' => 'Units', 'route' => 'tech.admin.settings.economy.unit'], 'params' => ['client' => $clients->id]]
        ['name' => 'x', 'route' => '#'],
        ['name' => 'x', 'route' => '#'],
    ];
-->

@props(['items' => null, 'title' => null])

<!-- ------------------------------------------------- -->
<!-- Workspace Sidebar Navigation -->
<!-- ------------------------------------------------- -->
<nav class="py-3" aria-label="{{ $title ?? 'Workspace navigation' }}">

    <!-- ------------------------------------------------- -->
    <!-- If there is any sidebar elements from the views controller -->
    <!-- ------------------------------------------------- -->
    @if(isset($items) && is_iterable($items))
        @if(!empty($title))
            <div class="px-2 mb-2">
                <div class="small text-uppercase fw-semibold text-muted">{{ $title }}</div>
            </div>
        @endif

        <!-- ------------------------------------------------- -->
        <!-- For each menu items in the controllers array -->
        <!-- ------------------------------------------------- -->
        <div class="nav nav-pills flex-column gap-1">
            @foreach($items as $item)

            @if(!empty($item['is_header']))
                <div class="mt-3 mb-1 px-2">
                    <span class="text-uppercase small fw-bold text-muted d-inline-flex align-items-center gap-1">
                        @if(!empty($item['icon']))
                            {{-- Optional icons make dense sidebar groups easier to scan. --}}
                            <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>
                        @endif
                        {{ $item['name'] }}
                        @if(!empty($item['help']))
                            <i
                                class="bi bi-question-circle text-muted"
                                title="{{ $item['help'] }}"
                                aria-label="{{ $item['help'] }}"
                            ></i>
                        @endif
                    </span>
                </div>
                @continue
            @endif

            <!-- Generate the href URL: if route name exists and is valid, generate the route URL with optional params, otherwise use '#' -->

            @php
                $isActive = request()->routeIs($item['pattern'] ?? $item['route']);

                // If the route matches, also check if all provided parameters match the current request
                if ($isActive && !empty($item['params']) && is_array($item['params'])) {
                    foreach ($item['params'] as $key => $value) {
                        if (request()->query($key) != $value && request()->route($key) != $value) {
                            $isActive = false;
                            break;
                        }
                    }
                }
            @endphp

            <a
                class="nav-link d-flex align-items-center gap-2 px-3 py-2 {{ $isActive ? 'active' : 'link-dark bg-light border' }}"
                href="{{ !empty($item['route']) && Route::has($item['route']) ? route($item['route'], $item['params'] ?? []) : '#' }}"
                @if($isActive) aria-current="page" @endif>

                @if(!empty($item['icon']))
                    {{-- Menu-provided icons are decorative because the link text names the destination. --}}
                    <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>
                @endif
                <span>{{ $item['name'] ?? $item['label'] ?? '' }}</span>
                @if(!empty($item['badge']))
                    <span class="ms-auto badge bg-secondary">{{ $item['badge'] }}</span>
                @elseif(!empty($item['label']) && !empty($item['name']))
                    <span class="ms-auto badge bg-secondary">{{ $item['label'] }}</span>
                @endif
            </a>
            @endforeach
        </div>

    <!-- ------------------------------------------------- -->
    <!-- If not try slot -->
    <!-- ------------------------------------------------- -->
    @else
        <p>Ingen elementer</p>
        {{$slot}}
    @endif

    @if(session('active_client_id'))
        <p>Du jobber nå med klient ID: {{ session('active_client_id') }}</p>
    @endif

    @if(session('active_site_id'))
        <p>Du jobber nå med site ID: {{ session('active_site_id') }}</p>
    @endif
</nav>
