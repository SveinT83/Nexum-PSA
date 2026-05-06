<?php

namespace App\Modules\UserManagement\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\UserManagement\Actions\StoreRole;
use App\Modules\UserManagement\Actions\UpdateRole;
use App\Modules\UserManagement\Menus\SideBar\UserManagementMenu;
use App\Modules\UserManagement\Queries\RoleQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class RolesManagementController extends Controller
{
    public function rolesIndex(RoleQuery $query, UserManagementMenu $menu): View
    {
        return view('usermanagement::Admin.Roles.index', [
            'sidebarMenuItems' => $menu->items(),
            'roles' => $query->allWithPermissions(),
        ]);
    }

    public function rolesEdit(int $id, UserManagementMenu $menu): View
    {
        return view('usermanagement::Admin.Roles.form', [
            'sidebarMenuItems' => $menu->items(),
            'role' => Role::with('permissions')->findOrFail($id),
            'users' => User::with('roles')->count(),
        ]);
    }

    public function rolesUpdate(Request $request, int $id, UpdateRole $action): RedirectResponse
    {
        $role = Role::findOrFail($id);

        $action->handle($role, $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,'.$role->id,
        ]));

        return redirect()->route('tech.admin.user_management.roles.edit', $role)
            ->with('success', 'Role name updated successfully.');
    }

    public function rolesCreate(UserManagementMenu $menu): View
    {
        return view('usermanagement::Admin.Roles.form', [
            'sidebarMenuItems' => $menu->items(),
        ]);
    }

    public function rolesStore(Request $request, StoreRole $action): RedirectResponse
    {
        $role = $action->handle($request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
        ]));

        return redirect()->route('tech.admin.user_management.roles.edit', $role)
            ->with('success', 'Role created successfully');
    }

    public function rolesDestroy(int $id): RedirectResponse
    {
        Role::findOrFail($id)->delete();

        return redirect()->route('tech.admin.user_management.roles.index')
            ->with('success', 'Role deleted successfully');
    }
}
