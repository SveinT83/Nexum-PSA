<!-- ------------------------------------------------- -->
<!-- Side Bar Menu -->
<!-- ------------------------------------------------- -->

<!--
    Use theis array in the active controller in order to use the side-bar menu

    $sidebarMenuItems = [
        ['name' => 'Dashboard', 'route' => 'tech.admin.settings.economy'],
        ['name' => 'Units', 'route' => 'tech.admin.settings.economy.unit'],
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

            <li class="nav-item">
                <a
                    class="nav-link {{ request()->routeIs($item['route']) ? 'active' : '' }}"
                    href="{{ route($item['route']) }}">
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
        <p>Ingene lementer</p>
        {{$slot}}
    @endif
</ul>
