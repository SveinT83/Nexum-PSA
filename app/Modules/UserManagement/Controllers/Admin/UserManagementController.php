<?php

namespace App\Modules\UserManagement\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\UserManagement\Actions\SendUserInvite;
use App\Modules\UserManagement\Actions\StoreUser;
use App\Modules\UserManagement\Actions\UpdateUserStatus;
use App\Modules\UserManagement\Menus\SideBar\UserManagementMenu;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\UserManagement\Queries\RoleQuery;
use App\Modules\UserManagement\Queries\UserQuery;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketTechnicianProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

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

    public function show(User $user, RoleQuery $roles, UserManagementMenu $menu): View
    {
        $user->load(['roles', 'permissions', 'profile', 'inviteTokens' => fn ($query) => $query->latest()]);
        $technicianProfile = TicketTechnicianProfile::with(['categories', 'tags'])
            ->where('user_id', $user->id)
            ->first();

        return view('usermanagement::Admin.show', [
            'sidebarMenuItems' => $menu->items(),
            'user' => $user,
            'profile' => $this->profileFor($user),
            'roles' => $roles->allWithPermissions(),
            'technicianProfile' => $technicianProfile,
            'openTicketCount' => Ticket::query()
                ->where('owner_id', $user->id)
                ->whereHas('status', fn ($query) => $query->where('is_closed', false))
                ->count(),
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

    public function updateProfile(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique((new User())->getTable(), 'email')->ignore($user->id),
            ],
            'phone_work' => 'nullable|string|max:50',
            'phone_private' => 'nullable|string|max:50',
            'timezone' => ['required', 'timezone'],
            'availability_notes' => ['nullable', 'string', 'max:5000'],
            'profile_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone_work' => $validated['phone_work'] ?? null,
            'phone_private' => $validated['phone_private'] ?? null,
        ])->save();

        UserProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'work_phone' => $validated['phone_work'] ?? null,
                'private_phone' => $validated['phone_private'] ?? null,
                'timezone' => $validated['timezone'],
                'availability_notes' => $validated['availability_notes'] ?? null,
                'profile_notes' => $validated['profile_notes'] ?? null,
            ]
        );

        return redirect()->route('tech.admin.user_management.show', $user)
            ->with('success', 'User profile updated successfully.');
    }

    public function updateStatus(Request $request, User $user, UpdateUserStatus $action): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:'.implode(',', [User::STATUS_PENDING, User::STATUS_ACTIVE, User::STATUS_DISABLED]),
        ]);

        $action->handle($user, $validated['status']);

        return redirect()->back(302, [], route('tech.admin.user_management.index'))
            ->with('success', 'User status updated successfully.');
    }

    public function updateRoles(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'roles' => 'nullable|array',
            'roles.*' => 'integer|exists:roles,id',
        ]);

        $roles = Role::query()
            ->whereIn('id', $validated['roles'] ?? [])
            ->get();

        $user->syncRoles($roles);

        return redirect()->route('tech.admin.user_management.show', $user)
            ->with('success', 'User roles updated successfully.');
    }

    /**
     * Send (or re-send) an invitation email to a pending user.
     */
    public function sendInvite(User $user, SendUserInvite $action): RedirectResponse
    {
        if (! $user->isPending()) {
            return redirect()->back(302, [], route('tech.admin.user_management.index'))
                ->with('error', 'Only pending users can receive invitations.');
        }

        $action->handle($user);

        return redirect()->back(302, [], route('tech.admin.user_management.index'))
            ->with('success', "Invitation sent to {$user->email}.");
    }

    private function profileFor(User $user): UserProfile
    {
        return UserProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'work_phone' => $user->phone_work,
                'private_phone' => $user->phone_private,
                'timezone' => config('app.timezone', 'UTC'),
            ]
        );
    }
}
