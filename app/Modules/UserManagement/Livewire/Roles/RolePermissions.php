<?php

namespace App\Modules\UserManagement\Livewire\Roles;

use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Livewire component for assigning permissions to a role.
 */
class RolePermissions extends Component
{
    public int $roleId;

    public Role $role;

    public array $selectedPermissions = [];

    public string $search = '';

    public function mount(int $roleId): void
    {
        $this->roleId = $roleId;
        $this->role = Role::with('permissions')->findOrFail($roleId);
        $this->selectedPermissions = $this->role->permissions->pluck('name')->toArray();
    }

    public function togglePermission(string $permissionName): void
    {
        // Read current membership under the shared action; stale UI state cannot invert it twice.
        $this->role = app(\App\Modules\UserManagement\Actions\SetRolePermission::class)->handle(
            Role::findOrFail($this->roleId), $permissionName,
            ! Role::findOrFail($this->roleId)->hasPermissionTo($permissionName));
        $this->selectedPermissions = $this->role->permissions->pluck('name')->all();

        session()->flash('message', 'Permissions updated successfully.');
    }

    public function render()
    {
        $permissions = Permission::where('name', 'like', '%'.$this->search.'%')
            ->orderBy('name')
            ->get()
            ->groupBy(function (Permission $permission) {
                $parts = explode('.', $permission->name);

                return count($parts) > 1 ? $parts[0] : 'General';
            });

        return view('usermanagement::Livewire.Roles.role-permissions', [
            'groupedPermissions' => $permissions,
        ]);
    }
}
