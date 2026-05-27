<?php

namespace App\Modules\UserManagement\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\UserManagement\Actions\SendUserInvite;
use App\Modules\UserManagement\Actions\StoreUser;
use App\Modules\UserManagement\Actions\UpdateUserStatus;
use App\Modules\UserManagement\Menus\SideBar\UserManagementMenu;
use App\Modules\UserManagement\Queries\RoleQuery;
use App\Modules\UserManagement\Queries\UserQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin controller for application users.
 *
 * "user_management" is the admin URL segment. The actual database table for
 * users is resolved by App\Models\Core\User and must not be inferred from the
 * route name.
 */
class UserManagementController extends Controller
{
    public function index(UserQuery $query, UserManagementMenu $menu): View
    {
        return view('usermanagement::Admin.index', [
            'sidebarMenuItems' => $menu->items(),
            'users' => $query->paginateForAdminIndex(),
        ]);
    }

    public function create(RoleQuery $roles, UserManagementMenu $menu): View
    {
        return view('usermanagement::Admin.form', [
            'sidebarMenuItems' => $menu->items(),
            'roles' => $roles->allWithPermissions(),
        ]);
    }

    public function store(Request $request, StoreUser $action): RedirectResponse
    {
        $action->handle($request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:'.(new User())->getTable().',email',
            'role' => 'nullable|exists:roles,id',
            'status' => 'nullable|in:'.implode(',', [User::STATUS_PENDING, User::STATUS_ACTIVE, User::STATUS_DISABLED]),
        ]));

        return redirect()->route('tech.admin.user_management.index')
            ->with('success', 'User created successfully.');
    }

    public function updateStatus(Request $request, User $user, UpdateUserStatus $action): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:'.implode(',', [User::STATUS_PENDING, User::STATUS_ACTIVE, User::STATUS_DISABLED]),
        ]);

        $action->handle($user, $validated['status']);

        return redirect()->route('tech.admin.user_management.index')
            ->with('success', 'User status updated successfully.');
    }

    /**
     * Send (or re-send) an invitation email to a pending user.
     */
    public function sendInvite(User $user, SendUserInvite $action): RedirectResponse
    {
        if (! $user->isPending()) {
            return redirect()->route('tech.admin.user_management.index')
                ->with('error', 'Only pending users can receive invitations.');
        }

        $action->handle($user);

        return redirect()->route('tech.admin.user_management.index')
            ->with('success', "Invitation sent to {$user->email}.");
    }
}
