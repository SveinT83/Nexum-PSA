<?php

namespace App\Http\Controllers\Tech\Admin\UserManagement;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Service\SideBarMenus\Admin\UserManagement;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RolesManagementController extends Controller
{
        public function rolesIndex()
    {
        // -----------------------------------------
        // Get sidemenu
        // -----------------------------------------
        $sidebarMenuItems = (new UserManagement())->UserManagement(null);

        // -----------------------------------------
        // Get all roles
        // -----------------------------------------
        $roles = Role::with('permissions')->get();

        // -----------------------------------------
        // Return view: sidebar menu items, roles
        // -----------------------------------------
        return view('tech.admin.user_management.roles.index', compact('sidebarMenuItems', 'roles'));
    }

        /**
         * ROLES EDIT
         * Shows the roles edit/create form
         *
         * @param int $id The ID of the role to edit
         * @return \Illuminate\View\View
         */
        public function rolesEdit($id){

        // -----------------------------------------
        // Get sidemenu
        // -----------------------------------------
        $sidebarMenuItems = (new UserManagement())->UserManagement(null);

        // -----------------------------------------
        // Get the role data with its permissions
        // -----------------------------------------
        $role = Role::with('permissions')->findOrFail($id);

        // -----------------------------------------
        // Get the number of users with their roles
        // -----------------------------------------
        $users = User::with('roles')->count();

        // -----------------------------------------
        // Return view: sidebar menu items, role
        // -----------------------------------------
        return view('tech.admin.user_management.roles.form', compact('sidebarMenuItems', 'role', 'users'));
    }

        /**
         * ROLES UPDATE
         * Updates the role name
         *
         * @param Request $request
         * @param int $id The ID of the role to update
         * @return \Illuminate\Http\RedirectResponse
         */
        public function rolesUpdate(Request $request, $id)
    {
        // -----------------------------------------
        // Validate the request
        // -----------------------------------------
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $id,
        ]);

        // -----------------------------------------
        // Get the role and update it
        // -----------------------------------------
        $role = Role::findOrFail($id);
        $role->name = $request->name;
        $role->save();

        // -----------------------------------------
        // Redirect back with success message
        // -----------------------------------------
        return redirect()->route('tech.admin.user_management.roles.edit', $id)
            ->with('success', 'Role name updated successfully.');
    }

        /**
         * ROLES Create
         * Show Create form
         *
         * @return \Illuminate\Http\RedirectResponse
         */
        public function rolesCreate(){

        // -----------------------------------------
        // Get sidemenu
        // -----------------------------------------
        $sidebarMenuItems = (new UserManagement())->UserManagement(null);

        // -----------------------------------------
        // Return view: sidebar menu items
        // -----------------------------------------
        return view('tech.admin.user_management.roles.form', compact('sidebarMenuItems'));
    }

        /**
         * ROLES STORE
         * Stores an new role
         *
         * @param Request $request
         * @return \Illuminate\Http\RedirectResponse
         */
        public function rolesStore(Request $request)
    {
        // -----------------------------------------
        // Validate the request
        // -----------------------------------------
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        // -----------------------------------------
        // Create the role
        // -----------------------------------------
        $role = Role::create($validated);

        // -----------------------------------------
        // Redirect to edit role form whit an Return success message
        // -----------------------------------------
        return redirect()->route('tech.admin.user_management.roles.edit', $role->id)->with('success', 'Role created successfully');
    }

        /**
         * ROLES DESTROY
         * Delete the role by ID
         *
         * @param int $id The ID of the role to edit
         * @return \Illuminate\View\View
         */
        public function rolesDestroy($id){

        // -----------------------------------------
        // Delete the role
        // -----------------------------------------
        $role = Role::findOrFail($id);
        $role->delete();

        // -----------------------------------------
        // Redirect to roles index
        // -----------------------------------------
        return redirect()->route('tech.admin.user_management.roles.index')->with('success', 'Role deleted successfully');
    }
}
