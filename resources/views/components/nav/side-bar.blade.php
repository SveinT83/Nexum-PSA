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

<ul class="nav sidebar flex-column">

    <!-- ------------------------------------------------- -->
    <!-- If there is any sidebar elements from the views controller -->
    <!-- ------------------------------------------------- -->
    @if(isset($items) && is_iterable($items))

        <!-- ------------------------------------------------- -->
        <!-- For each menu items in the controllers array -->
        <!-- ------------------------------------------------- -->
        @foreach($items as $item)

            @if(!empty($item['is_header']))
                <li class="nav-item mt-3 mb-1 px-3">
                    <span class="text-uppercase small fw-bold text-muted">{{ $item['name'] }}</span>
                </li>
                @continue
            @endif

            <!-- Generate the href URL: if route name exists and is valid, generate the route URL with optional params, otherwise use '#' -->

            <li class="nav-item">
                <a
                    class="nav-link {{ request()->routeIs($item['route']) ? 'active' : '' }}"
                    href="{{ !empty($item['route']) && Route::has($item['route']) ? route($item['route'], $item['params'] ?? []) : '#' }}">

                    {{ $item['name'] ?? '' }}
                    @if(!empty($item['label']))
                        <span class="ms-2 badge bg-secondary">{{ $item['label'] }}</span>
                    @endif
                </a>
            </li>
        @endforeach

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
</ul>
