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
        if (in_array($permissionName, $this->selectedPermissions, true)) {
            $this->role->revokePermissionTo($permissionName);
            $this->selectedPermissions = array_values(array_diff($this->selectedPermissions, [$permissionName]));
        } else {
            $this->role->givePermissionTo($permissionName);
            $this->selectedPermissions[] = $permissionName;
        }

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
