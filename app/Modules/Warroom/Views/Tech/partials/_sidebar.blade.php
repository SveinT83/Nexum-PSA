@php
    $sidebarMenuItems = [
        ['name' => 'Dashboard', 'route' => 'tech.dashboard', 'icon' => 'bi-speedometer2'],
        ['name' => 'My Day', 'route' => 'tech.my-day.index', 'icon' => 'bi-sun'],
    ];

    if (Route::has('tech.warroom.lanes')) {
         $sidebarMenuItems[] = ['name' => 'Lanes', 'route' => 'tech.warroom.lanes', 'icon' => 'bi-columns-gap'];
    }

    if (Route::has('tech.warroom.settings.edit')) {
         $sidebarMenuItems[] = ['name' => 'Settings', 'route' => 'tech.warroom.settings.edit', 'icon' => 'bi-gear'];
    }
@endphp

<x-nav.side-bar :items="$sidebarMenuItems" title="Warroom" />
