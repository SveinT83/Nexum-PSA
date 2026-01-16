<?php

/**
 * Class responsible for generating sidebar menu items related to clients.
 * Provides a structured list of menu items for client-related actions and navigation.
 */

namespace App\Service\SideBarMenus;

use /**
 * ClientSite Model
 *
 * Represents a client site associated with the NexumPSA application.
 * This model interacts with the `client_sites` table in the MySQL database.
 *
 * Key Features:
 * - Defines relationships between client sites and other domain entities.
 * - Facilitates querying, creation, and updates to client site records.
 * - Supports queue jobs using the `database` queue connection.
 *
 * Laravel Version Compatibility:
 * - Built for Laravel v12.32.5.
 *
 * Database:
 * - Uses MySQL as the database engine.
 * - Associated with the `client_sites` table.
 *
 * General Usage:
 * - Intended for representing client-specific site data for PSA (Professional Services Automation) processes.
 * - Can include functionality such as scopes, attribute accessors, and mutators for streamlined operations.
 *
 * Queue Connection:
 * - Supports queued operations via the `database` queue driver.
 *
 * Relationships:
 * - Definitions of relationships with other models should be present in this class.
 */
    App\Models\Clients\ClientSite;

/**
 * Generates a menu for the sidebar with links specific to client management.
 */
class ClientsMenu
{
    /**
     * Generates an array of sidebar menu items for the clients section.
     *
     * This method constructs the navigation menu structure for client management,
     * including links to clients overview, users, and client-specific sites.
     * The Sites menu item is conditionally configured based on whether a specific
     * client object is provided and valid.
     *
     * @param mixed $clients The client object (typically an instance of Client model)
     *                       or null if no specific client is selected.
     *                       When provided with a valid client, the Sites menu will
     *                       include the client ID as a route parameter.
     *
     * @return array An array of menu items, where each item contains:
     *               - 'name' (string): The display name of the menu item
     *               - 'route' (string): The named route for navigation
     *               - 'params' (array|string): Optional route parameters
     */
    public function ClientsMenu($clients): array
    {
        // Initialize an empty array to hold the menu items
        $sidebarMenuItems = [];

        // Add the main "Clients" menu item linking to the clients index page
        $sidebarMenuItems[] = ['name' => 'Clients', 'route' => 'tech.clients.index'];

        // Add the "Users" menu item linking to the client users index page
        $sidebarMenuItems[] = ['name' => 'Users', 'route' => 'tech.clients.users.index'];

        // Conditionally configure the "Sites" menu item based on client validity
        if ($clients && is_numeric($clients->id)) {
            // If a valid client object exists with a numeric ID, include the client ID
            // as a route parameter to show sites specific to this client
            $sidebarMenuItems[] = [
                'name' => 'Sites',
                'route' => 'tech.clients.sites.index',
                'params' => ['client' => $clients->id]
            ];
        } else {
            // If no valid client is provided, add Sites menu with a placeholder parameter
            // The 'x' parameter likely triggers a default view or all sites view
            $sidebarMenuItems[] = ['name' => 'Sites', 'route' => 'tech.clients.sites.index', 'params' => 'x'];
        }

        // Return the complete array of sidebar menu items
        return $sidebarMenuItems;
    }

}
