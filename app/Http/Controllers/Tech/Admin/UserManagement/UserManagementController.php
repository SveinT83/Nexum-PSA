<?php

namespace App\Http\Controllers\Tech\Admin\UserManagement;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Service\SideBarMenus\Admin\UserManagement;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{

    /**
     * USER INDEX
     * Shows the user management dashboard
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {

        // -----------------------------------------
        // Get sidemenu
        // -----------------------------------------
        $sidebarMenuItems = (new UserManagement())->UserManagement(null);

        // -----------------------------------------
        // Get al Tech users
        // -----------------------------------------
        //$users = User::technicians();

        // -----------------------------------------
        // Return view: sidebar menu items
        // -----------------------------------------
        return view('tech.admin.user_management.index', compact('sidebarMenuItems'));
    }

    /**
     * USER CREATE
     * Shows the crete user form
     *
     * @return \Illuminate\View\View
     */
    public function create() {

        // -----------------------------------------
        // Get sidemenu
        // -----------------------------------------
        $sidebarMenuItems = (new UserManagement())->UserManagement(null);

        // -----------------------------------------
        // Get all roles
        // -----------------------------------------
        $roles = Role::with('permissions')->get();

        // -----------------------------------------
        // Return view: sidebar menu items
        // -----------------------------------------
        return view('tech.admin.user_management.form', compact('sidebarMenuItems', 'roles'));

    }

}
