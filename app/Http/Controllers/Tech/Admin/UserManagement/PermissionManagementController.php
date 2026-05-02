<?php

namespace App\Http\Controllers\Tech\Admin\UserManagement;

use App\Http\Controllers\Controller;
use App\Service\SideBarMenus\Admin\UserManagement;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * PermissionManagementController
 *
 * Handles the CRUD operations for system permissions.
 */
class PermissionManagementController extends Controller
{
    /**
     * PERMISSIONS INDEX
     * Shows the permissions management dashboard
     *
     * @return \Illuminate\View\View
     */
    public function permissionsIndex()
    {
        // -----------------------------------------
        // Get sidemenu
        // -----------------------------------------
        $sidebarMenuItems = (new UserManagement())->UserManagement(null);

        // -----------------------------------------
        // Get all permissions
        // -----------------------------------------
        $permissions = Permission::all();

        // -----------------------------------------
        // Return view: sidebar menu items, permissions
        // -----------------------------------------
        return view('tech.admin.user_management.permissions.index', compact('sidebarMenuItems', 'permissions'));
    }

    /**
     * PERMISSIONS CREATE
     * Shows the permission creation form
     *
     * @return \Illuminate\View\View
     */
    public function permissionsCreate()
    {
        // -----------------------------------------
        // Get sidemenu
        // -----------------------------------------
        $sidebarMenuItems = (new UserManagement())->UserManagement(null);

        // -----------------------------------------
        // Return view: sidebar menu items
        // -----------------------------------------
        return view('tech.admin.user_management.permissions.form', compact('sidebarMenuItems'));
    }

    /**
     * PERMISSIONS STORE
     * Stores a new permission
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function permissionsStore(Request $request)
    {
        // -----------------------------------------
        // Validate the request
        // -----------------------------------------
        $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name',
        ]);

        // -----------------------------------------
        // Create the permission
        // -----------------------------------------
        $permission = Permission::create(['name' => $request->name]);

        // -----------------------------------------
        // Redirect back with success message
        // -----------------------------------------
        return redirect()->route('tech.admin.user_management.permissions.edit', $permission->id)
            ->with('success', 'Permission created successfully.');
    }

    /**
     * PERMISSIONS EDIT
     * Shows the permission edit form
     *
     * @param int $id The ID of the permission to edit
     * @return \Illuminate\View\View
     */
    public function permissionsEdit($id)
    {
        // -----------------------------------------
        // Get sidemenu
        // -----------------------------------------
        $sidebarMenuItems = (new UserManagement())->UserManagement(null);

        // -----------------------------------------
        // Get the permission data
        // -----------------------------------------
        $permission = Permission::findOrFail($id);

        // -----------------------------------------
        // Return view: sidebar menu items, permission
        // -----------------------------------------
        return view('tech.admin.user_management.permissions.form', compact('sidebarMenuItems', 'permission'));
    }

    /**
     * PERMISSIONS UPDATE
     * Updates the permission name
     *
     * @param Request $request
     * @param int $id The ID of the permission to update
     * @return \Illuminate\Http\RedirectResponse
     */
    public function permissionsUpdate(Request $request, $id)
    {
        // -----------------------------------------
        // Validate the request
        // -----------------------------------------
        $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name,' . $id,
        ]);

        // -----------------------------------------
        // Get the permission and update it
        // -----------------------------------------
        $permission = Permission::findOrFail($id);
        $permission->name = $request->name;
        $permission->save();

        // -----------------------------------------
        // Redirect back with success message
        // -----------------------------------------
        return redirect()->route('tech.admin.user_management.permissions.edit', $id)
            ->with('success', 'Permission name updated successfully.');
    }

    /**
     * PERMISSIONS DESTROY
     * Delete the permission by ID
     *
     * @param int $id The ID of the permission to delete
     * @return \Illuminate\Http\RedirectResponse
     */
    public function permissionsDestroy($id)
    {
        // -----------------------------------------
        // Delete the permission
        // -----------------------------------------
        $permission = Permission::findOrFail($id);
        $permission->delete();

        // -----------------------------------------
        // Redirect to permissions index
        // -----------------------------------------
        return redirect()->route('tech.admin.user_management.permissions.index')
            ->with('success', 'Permission deleted successfully.');
    }
}
