<?php

namespace App\Modules\UserManagement\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Actions\StorePermission;
use App\Modules\UserManagement\Actions\UpdatePermission;
use App\Modules\UserManagement\Menus\SideBar\UserManagementMenu;
use App\Modules\UserManagement\Queries\PermissionQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;

class PermissionManagementController extends Controller
{
    public function permissionsIndex(PermissionQuery $query, UserManagementMenu $menu): View
    {
        return view('usermanagement::Admin.Permissions.index', [
            'sidebarMenuItems' => $menu->items(),
            'permissions' => $query->allOrdered(),
        ]);
    }

    public function permissionsCreate(UserManagementMenu $menu): View
    {
        return view('usermanagement::Admin.Permissions.form', [
            'sidebarMenuItems' => $menu->items(),
        ]);
    }

    public function permissionsStore(Request $request, StorePermission $action): RedirectResponse
    {
        $permission = $action->handle($request->validate([
            'name' => 'required|string|max:255|unique:permissions,name',
        ]));

        return redirect()->route('tech.admin.user_management.permissions.edit', $permission)
            ->with('success', 'Permission created successfully.');
    }

    public function permissionsEdit(int $id, UserManagementMenu $menu): View
    {
        return view('usermanagement::Admin.Permissions.form', [
            'sidebarMenuItems' => $menu->items(),
            'permission' => Permission::findOrFail($id),
        ]);
    }

    public function permissionsUpdate(Request $request, int $id, UpdatePermission $action): RedirectResponse
    {
        $permission = Permission::findOrFail($id);

        $action->handle($permission, $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name,'.$permission->id,
        ]));

        return redirect()->route('tech.admin.user_management.permissions.edit', $permission)
            ->with('success', 'Permission name updated successfully.');
    }

    public function permissionsDestroy(int $id): RedirectResponse
    {
        Permission::findOrFail($id)->delete();

        return redirect()->route('tech.admin.user_management.permissions.index')
            ->with('success', 'Permission deleted successfully.');
    }
}
