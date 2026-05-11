<?php

namespace App\Modules\Documentation\Menus\SideBar;

class TemplatesMenu
{
    public function TemplatesMenu($selectedItem = null): array
    {
        // Initialize an empty array to hold the menu items
        $sidebarMenuItems = [];

        // Add the main navigation items for clients, sites, and user_management
        $sidebarMenuItems[] = ['name' => 'Documentations', 'route' => 'tech.admin.system.templatesManagement.doc.index'];
        $sidebarMenuItems[] = ['name' => 'Email', 'route' => 'tech.admin.system.templatesManagement.email.index'];

        return $sidebarMenuItems;
    }
}
