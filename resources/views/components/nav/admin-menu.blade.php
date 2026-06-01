@props([
    'group' => null,
    'title' => null,
    'localTitle' => null,
    'localItems' => null,
])

@php
    /*
    |--------------------------------------------------------------------------
    | Admin settings navigation
    |--------------------------------------------------------------------------
    |
    | Admin pages share two navigation layers:
    | 1. Admin areas, matching the card groups on the Admin landing page.
    | 2. Local settings links for the current area.
    |
    | Route guards keep the component usable while modules are moved or added.
    |
    */
    $adminAreas = [
        'commercial' => [
            'name' => 'Commercial',
            'route' => 'tech.admin.settings.cs.contracts',
            'pattern' => 'tech.admin.settings.cs.*',
            'icon' => 'bi-briefcase',
        ],
        'economy' => [
            'name' => 'Economy',
            'route' => 'tech.admin.settings.economy',
            'pattern' => 'tech.admin.settings.economy',
            'icon' => 'bi-receipt',
        ],
        'email' => [
            'name' => 'Email',
            'route' => 'tech.admin.settings.email.accounts',
            'pattern' => 'tech.admin.settings.email.*',
            'icon' => 'bi-envelope-at',
        ],
        'sales' => [
            'name' => 'Sales',
            'route' => 'tech.admin.settings.sales.rules',
            'pattern' => 'tech.admin.settings.sales.*',
            'icon' => 'bi-graph-up-arrow',
        ],
        'clients' => [
            'name' => 'Clients',
            'route' => 'tech.admin.settings.clients.client-formats',
            'pattern' => ['tech.admin.settings.clients.*', 'tech.admin.settings.contacts*'],
            'icon' => 'bi-buildings',
        ],
        'contacts' => [
            'name' => 'Contacts',
            'route' => 'tech.admin.settings.contacts',
            'pattern' => 'tech.admin.settings.contacts*',
            'icon' => 'bi-person-lines-fill',
        ],
        'calendar' => [
            'name' => 'Calendar',
            'route' => 'tech.admin.settings.calendar',
            'pattern' => 'tech.admin.settings.calendar*',
            'icon' => 'bi-calendar-week',
        ],
        'storage' => [
            'name' => 'Storage',
            'route' => 'tech.admin.settings.storage.inventory',
            'pattern' => 'tech.admin.settings.storage.*',
            'icon' => 'bi-box-seam',
        ],
        'assets' => [
            'name' => 'Assets',
            'route' => 'tech.admin.settings.assets',
            'pattern' => 'tech.admin.settings.assets*',
            'icon' => 'bi-hdd-network',
        ],
        'tickets' => [
            'name' => 'Tickets',
            'route' => 'tech.admin.settings.tickets',
            'pattern' => ['tech.admin.settings.tickets*', 'tech.admin.settings.tasks*'],
            'icon' => 'bi-ticket-detailed',
        ],
        'tasks' => [
            'name' => 'Tasks',
            'route' => 'tech.admin.settings.tasks',
            'pattern' => 'tech.admin.settings.tasks*',
            'icon' => 'bi-list-task',
        ],
        'templates' => [
            'name' => 'Templates',
            'route' => 'tech.admin.system.templatesManagement.index',
            'pattern' => ['tech.admin.system.templatesManagement.*', 'tech.admin.settings.knowledge*'],
            'icon' => 'bi-layout-text-window-reverse',
        ],
        'knowledge' => [
            'name' => 'Knowledge',
            'route' => 'tech.admin.settings.knowledge',
            'pattern' => 'tech.admin.settings.knowledge*',
            'icon' => 'bi-journal-text',
        ],
        'users' => [
            'name' => 'Users',
            'route' => 'tech.admin.user_management.index',
            'pattern' => 'tech.admin.user_management.*',
            'icon' => 'bi-people',
        ],
        'system' => [
            'name' => 'System',
            'route' => 'tech.admin.system.category.index',
            'pattern' => [
                'tech.admin.system.category.*',
                'tech.admin.system.tag.*',
                'tech.admin.system.company-profile.*',
                'tech.admin.system.branding.*',
                'tech.admin.settings.warroom*',
                'tech.admin.settings.risk*',
                'tech.admin.system.queues-workers.*',
                'tech.admin.notification-channels.*',
            ],
            'icon' => 'bi-sliders',
        ],
        'risk' => [
            'name' => 'Risk',
            'route' => 'tech.admin.settings.risk',
            'pattern' => 'tech.admin.settings.risk*',
            'icon' => 'bi-shield-check',
        ],
        'warroom' => [
            'name' => 'Warroom',
            'route' => 'tech.admin.settings.warroom',
            'pattern' => 'tech.admin.settings.warroom*',
            'icon' => 'bi-speedometer2',
        ],
        'integrations' => [
            'name' => 'Integrations',
            'route' => 'tech.admin.system.integrations.index',
            'pattern' => 'tech.admin.system.integrations.*',
            'icon' => 'bi-plug',
        ],
    ];

    $localGroups = [
        'commercial' => [
            ['name' => 'Contracts', 'route' => 'tech.admin.settings.cs.contracts', 'pattern' => 'tech.admin.settings.cs.contracts'],
            ['name' => 'Services', 'route' => 'tech.admin.settings.cs.services', 'pattern' => 'tech.admin.settings.cs.services'],
            ['name' => 'Units', 'route' => 'tech.admin.settings.economy.units', 'pattern' => 'tech.admin.settings.economy.units*'],
        ],
        'economy' => [
            ['name' => 'Orders and billing', 'route' => 'tech.admin.settings.economy', 'pattern' => 'tech.admin.settings.economy'],
        ],
        'email' => [
            ['name' => 'Accounts', 'route' => 'tech.admin.settings.email.accounts', 'pattern' => 'tech.admin.settings.email.accounts*'],
            ['name' => 'Config', 'route' => 'tech.admin.settings.email.config', 'pattern' => 'tech.admin.settings.email.config*'],
            ['name' => 'Rules', 'route' => 'tech.admin.settings.email.rules', 'pattern' => 'tech.admin.settings.email.rules*'],
            ['name' => 'Templates', 'route' => 'tech.admin.system.templatesManagement.email.index', 'pattern' => 'tech.admin.system.templatesManagement.email.*'],
        ],
        'sales' => [
            ['name' => 'Rules', 'route' => 'tech.admin.settings.sales.rules', 'pattern' => 'tech.admin.settings.sales.rules*'],
            ['name' => 'Workflows', 'route' => 'tech.admin.settings.sales.workflows', 'pattern' => 'tech.admin.settings.sales.workflows*'],
        ],
        'clients' => [
            ['name' => 'Client formats', 'route' => 'tech.admin.settings.clients.client-formats', 'pattern' => 'tech.admin.settings.clients.client-formats*'],
            ['name' => 'Contact settings', 'route' => 'tech.admin.settings.contacts', 'pattern' => 'tech.admin.settings.contacts*'],
        ],
        'contacts' => [
            ['name' => 'Contact settings', 'route' => 'tech.admin.settings.contacts', 'pattern' => 'tech.admin.settings.contacts*'],
        ],
        'calendar' => [
            ['name' => 'Calendar settings', 'route' => 'tech.admin.settings.calendar', 'pattern' => 'tech.admin.settings.calendar*'],
        ],
        'storage' => [
            ['name' => 'Inventory settings', 'route' => 'tech.admin.settings.storage.inventory', 'pattern' => 'tech.admin.settings.storage.inventory*'],
        ],
        'assets' => [
            ['name' => 'Asset settings', 'route' => 'tech.admin.settings.assets', 'pattern' => 'tech.admin.settings.assets*'],
        ],
        'tickets' => [
            ['name' => 'Ticket settings', 'route' => 'tech.admin.settings.tickets', 'pattern' => 'tech.admin.settings.tickets'],
            ['name' => 'Technicians', 'route' => 'tech.admin.settings.tickets.technicians', 'pattern' => 'tech.admin.settings.tickets.technicians*'],
            ['name' => 'Assignment rules', 'route' => 'tech.admin.settings.tickets.assignment-rules', 'pattern' => 'tech.admin.settings.tickets.assignment-rules*'],
            ['name' => 'Rules', 'route' => 'tech.admin.settings.tickets.rules', 'pattern' => 'tech.admin.settings.tickets.rules*'],
            ['name' => 'Workflows', 'route' => 'tech.admin.settings.tickets.workflows', 'pattern' => 'tech.admin.settings.tickets.workflows*'],
        ],
        'templates' => [
            ['name' => 'Template management', 'route' => 'tech.admin.system.templatesManagement.index', 'pattern' => 'tech.admin.system.templatesManagement.index'],
            ['name' => 'Document templates', 'route' => 'tech.admin.system.templatesManagement.doc.index', 'pattern' => 'tech.admin.system.templatesManagement.doc.*'],
            ['name' => 'Email templates', 'route' => 'tech.admin.system.templatesManagement.email.index', 'pattern' => 'tech.admin.system.templatesManagement.email.*'],
        ],
        'users' => [
            ['name' => 'User management', 'route' => 'tech.admin.user_management.index', 'pattern' => 'tech.admin.user_management.index'],
            ['name' => 'Roles', 'route' => 'tech.admin.user_management.roles.index', 'pattern' => 'tech.admin.user_management.roles.*'],
            ['name' => 'Permissions', 'route' => 'tech.admin.user_management.permissions.index', 'pattern' => 'tech.admin.user_management.permissions.*'],
            ['name' => 'Two-factor auth', 'route' => 'tech.admin.user_management.2fa-settings', 'pattern' => 'tech.admin.user_management.2fa-settings*'],
        ],
        'system' => [
            ['name' => 'Company profile', 'route' => 'tech.admin.system.company-profile.edit', 'pattern' => 'tech.admin.system.company-profile.*'],
            ['name' => 'Branding', 'route' => 'tech.admin.system.branding.edit', 'pattern' => 'tech.admin.system.branding.*'],
            ['name' => 'Categories', 'route' => 'tech.admin.system.category.index', 'pattern' => 'tech.admin.system.category.*'],
            ['name' => 'Tags', 'route' => 'tech.admin.system.tag.index', 'pattern' => 'tech.admin.system.tag.*'],
            ['name' => 'Queues and workers', 'route' => 'tech.admin.system.queues-workers.index', 'pattern' => 'tech.admin.system.queues-workers.*'],
            ['name' => 'Notification channels', 'route' => 'tech.admin.notification-channels.index', 'pattern' => 'tech.admin.notification-channels.*'],
        ],
        'integrations' => [
            ['name' => 'All integrations', 'route' => 'tech.admin.system.integrations.index', 'pattern' => 'tech.admin.system.integrations.index'],
            ['name' => 'N-able RMM', 'route' => 'tech.admin.system.integrations.nable_rmm.settings', 'pattern' => 'tech.admin.system.integrations.nable_rmm.*'],
            ['name' => 'Tactical RMM', 'route' => 'tech.admin.system.integrations.tactical_rmm.settings', 'pattern' => 'tech.admin.system.integrations.tactical_rmm.*'],
            ['name' => 'BookStack', 'route' => 'tech.admin.system.integrations.book_stack.settings', 'pattern' => 'tech.admin.system.integrations.book_stack.*'],
            ['name' => 'API management', 'route' => 'tech.admin.system.integrations.api.index', 'pattern' => 'tech.admin.system.integrations.api.*'],
            ['name' => 'AI settings', 'route' => 'tech.admin.system.integrations.ai.index', 'pattern' => 'tech.admin.system.integrations.ai.*'],
            ['name' => 'Nextcloud', 'route' => 'tech.admin.nextcloud.connections.index', 'pattern' => 'tech.admin.nextcloud.*'],
        ],
    ];

    $adminItems = collect($adminAreas)
        ->filter(fn ($item) => Route::has($item['route']))
        ->values()
        ->all();

    $activeArea = $group && isset($adminAreas[$group]) ? $adminAreas[$group]['name'] : null;
    $resolvedLocalItems = $localItems ?? ($group && isset($localGroups[$group]) ? $localGroups[$group] : []);
    $resolvedLocalItems = collect($resolvedLocalItems)
        ->filter(fn ($item) => ! empty($item['route']) && Route::has($item['route']))
        ->values()
        ->all();

    $items = [];

    if (! empty($resolvedLocalItems)) {
        $items[] = [
            'is_header' => true,
            'name' => $localTitle ?? ($activeArea ? $activeArea . ' settings' : 'Settings'),
            'icon' => $group && isset($adminAreas[$group]) ? $adminAreas[$group]['icon'] : 'bi-sliders',
        ];
        $items = array_merge($items, $resolvedLocalItems);
    }

    $items[] = ['is_header' => true, 'name' => 'Admin areas', 'icon' => 'bi-grid'];
    $items = array_merge($items, $adminItems);
@endphp

<x-nav.side-bar :title="$title" :items="$items" />
