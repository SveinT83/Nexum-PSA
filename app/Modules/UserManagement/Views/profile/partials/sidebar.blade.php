@php
    /*
     * The profile shell is owned by UserManagement, but some existing profile
     * sections still live in their original domains until the consolidation
     * slices migrate their data ownership.
     */
    $profileSidebarItems = [
        [
            'name' => 'Account',
            'route' => 'tech.profile.index',
            'pattern' => ['tech.profile.index'],
            'icon' => 'bi-person-badge',
        ],
        [
            'name' => 'Preferences',
            'route' => 'tech.profile.preferences',
            'pattern' => ['tech.profile.preferences*'],
            'icon' => 'bi-sliders',
        ],
        [
            'name' => 'Security / 2FA',
            'route' => 'tech.profile.security',
            'pattern' => ['tech.profile.security*'],
            'icon' => 'bi-shield-lock',
        ],
    ];

    if (Route::has('tech.profile.notifications')) {
        $profileSidebarItems[] = [
            'name' => 'Notifications',
            'route' => 'tech.profile.notifications',
            'pattern' => ['tech.profile.notifications*'],
            'icon' => 'bi-bell',
        ];
    }

    if (Route::has('tech.tickets.profile.edit')) {
        $profileSidebarItems[] = [
            'name' => 'Ticket assignment',
            'route' => 'tech.tickets.profile.edit',
            'pattern' => ['tech.tickets.profile*'],
            'icon' => 'bi-calendar-check',
        ];
    }

    $profileSidebarItems[] = [
        'name' => 'Integrations',
        'route' => 'tech.profile.integrations',
        'pattern' => ['tech.profile.integrations*'],
        'icon' => 'bi-plug',
    ];

    $profileSidebarItems[] = [
        'name' => 'View',
        'route' => 'tech.profile.view',
        'pattern' => ['tech.profile.view*'],
        'icon' => 'bi-palette',
    ];
@endphp

<x-nav.side-bar title="Profile" :items="$profileSidebarItems" />
