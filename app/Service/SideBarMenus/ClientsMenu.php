<?php

namespace App\Service\SideBarMenus;

use App\Models\Clients\ClientSite;

class ClientsMenu
{
    /**
     * Generates the sidebar menu for the clients section.
     *
     * @param mixed $clients Optional client model or ID
     * @param mixed $sites Optional site model or ID
     * @return array A structured list of sidebar menu items
     */
    public function ClientsMenu($clients = null, $sites = null): array
    {
        // Initialize an empty array to hold the menu items
        $sidebarMenuItems = [];

        // Add the main navigation items for clients, sites, and users
        $sidebarMenuItems[] = ['name' => 'Clients', 'route' => 'tech.clients.index'];
        $sidebarMenuItems[] = ['name' => 'Sites', 'route' => 'tech.clients.sites.index'];
        $sidebarMenuItems[] = ['name' => 'Users', 'route' => 'tech.clients.users.index'];

        // --- Documentation Section ---
        // This section mirrors the documentation sidebar but within the client context.
        // It provides quick access to documentation categories for the active client.
        $sidebarMenuItems[] = ['name' => 'Dokumentations', 'is_header' => true];

        // Retrieve all categories that have at least one documentation template associated
        $categories = \App\Models\Doc\Category::has('templates')
            ->where('is_active', true)
            ->get();

        // Add "All" option to view all client-related documentations
        $sidebarMenuItems[] = [
            'name' => 'All',
            'route' => 'tech.documentations.index',
            'params' => ['cat' => 'all', 'exclude_internal' => 1]
        ];

        // Add each valid category to the sidebar
        foreach ($categories as $category) {
            $sidebarMenuItems[] = [
                'name' => $category->name,
                'route' => 'tech.documentations.index',
                'params' => [
                    'cat' => $category->slug,
                    'exclude_internal' => 1 // Ensures internal documents are hidden in client context
                ],
            ];
        }

        // Return the complete array of sidebar menu items
        return $sidebarMenuItems;
    }
}
