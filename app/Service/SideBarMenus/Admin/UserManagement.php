<?php

namespace App\Service\SideBarMenus\Admin;

class UserManagement
{
    public function UserManagement($selectedItem = null): array
    {
        // Initialize an empty array to hold the menu items
        $sidebarMenuItems = [];

        // Add the main navigation items for clients, sites, and user_management
        $sidebarMenuItems[] = ['name' => 'Users Management', 'route' => 'tech.admin.user_management.index'];
        $sidebarMenuItems[] = ['name' => 'Roles', 'route' => 'tech.admin.user_management.roles.index'];
        $sidebarMenuItems[] = ['name' => 'Permissions', 'route' => 'tech.admin.user_management.permissions.index'];

        return $sidebarMenuItems;
    }
}
