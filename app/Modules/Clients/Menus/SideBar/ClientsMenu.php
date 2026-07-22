<?php

namespace App\Modules\Clients\Menus\SideBar;

use App\Models\Clients\Client;
use App\Modules\Taxonomy\Models\Category;

class ClientsMenu
{
    /**
     * Generates the sidebar menu for the clients section.
     *
     * @param  mixed  $clients  Optional client model or ID
     * @param  mixed  $sites  Optional site model or ID
     * @return array A structured list of sidebar menu items
     */
    public function ClientsMenu($clients = null, $sites = null): array
    {
        // Initialize an empty array to hold the menu items
        $sidebarMenuItems = [];

        // Add the primary client workspace areas before the documentation shortcuts.
        $sidebarMenuItems[] = [
            'name' => 'Clients',
            'route' => 'tech.clients.index',
            'icon' => 'bi-building',
        ];

        $clientParam = $clients instanceof Client ? ['client' => $clients->id] : ($clients ? ['client' => $clients] : []);

        $sidebarMenuItems[] = [
            'name' => 'Sites',
            'route' => 'tech.clients.sites.index',
            'params' => $clientParam,
            'icon' => 'bi-diagram-3',
        ];

        $sidebarMenuItems[] = [
            'name' => 'Assets',
            // The Asset module owns asset routes. Without a client context the
            // sidebar must link to the global asset list; the client-scoped
            // route requires a `{client}` parameter.
            'route' => $clientParam ? 'tech.clients.assets.index' : 'tech.assets.index',
            'params' => $clientParam,
            'icon' => 'bi-pc-display',
        ];

        if ($clientParam) {
            $sidebarMenuItems[] = [
                'name' => 'Licences',
                'route' => 'tech.clients.licenses.index',
                'params' => $clientParam,
                'icon' => 'bi-key',
            ];
        }

        $sidebarMenuItems[] = [
            'name' => 'Contacts',
            'route' => 'tech.contacts.index',
            'icon' => 'bi-person-lines-fill',
        ];

        // --- Documentation Section ---
        // This mirrors a Passportal-style client documentation vault inside the client context.
        $sidebarMenuItems[] = [
            'name' => 'Documentation',
            'is_header' => true,
            'icon' => 'bi-folder2-open',
            'help' => 'Client documentation shortcuts grouped by template category, similar to Passportal.',
        ];

        // Retrieve all categories that have at least one documentation template associated
        $categories = Category::has('templates')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        // Add "All" option to view all client-related documentations
        $sidebarMenuItems[] = [
            'name' => 'All Documentation',
            'route' => 'tech.documentations.index',
            'params' => ['cat' => 'all', 'exclude_internal' => 1],
            'icon' => 'bi-question-circle',
        ];

        // Add each valid category to the sidebar
        foreach ($categories as $category) {
            $sidebarMenuItems[] = [
                'name' => $category->name,
                'route' => 'tech.documentations.index',
                'icon' => 'bi-file-earmark-text',
                'params' => [
                    'cat' => $category->slug,
                    'exclude_internal' => 1, // Ensures internal documents are hidden in client context
                ],
            ];
        }

        // Return the complete array of sidebar menu items
        return $sidebarMenuItems;
    }
}
