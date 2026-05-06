<?php

namespace App\Modules\UserManagement\Menus\SideBar;

/**
 * Sidebar menu for the User Management admin area.
 *
 * This replaces the legacy app/Service/SideBarMenus/Admin/UserManagement menu
 * for this module. The route names intentionally keep the existing
 * tech.admin.user_management.* contract so navigation links do not change.
 */
class UserManagementMenu
{
    public function items(): array
    {
        return [
            ['name' => 'Users Management', 'route' => 'tech.admin.user_management.index'],
            ['name' => 'Roles', 'route' => 'tech.admin.user_management.roles.index'],
            ['name' => 'Permissions', 'route' => 'tech.admin.user_management.permissions.index'],
        ];
    }
}
