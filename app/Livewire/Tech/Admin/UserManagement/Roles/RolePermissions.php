<?php

namespace App\Livewire\Tech\Admin\UserManagement\Roles;

use Livewire\Component;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Collection;

/**
 * RolePermissions Component
 *
 * This component manages the assignment of permissions to a specific role.
 * It provides a dynamic interface for toggling permissions.
 */
class RolePermissions extends Component
{
    public $roleId;
    public $role;
    public $selectedPermissions = [];
    public $search = '';

    /**
     * Initialize the component
     */
    public function mount($roleId)
    {
        $this->roleId = $roleId;
        $this->role = Role::with('permissions')->findOrFail($roleId);
        $this->selectedPermissions = $this->role->permissions->pluck('name')->toArray();
    }

    /**
     * Toggle a permission for the role
     */
    public function togglePermission($permissionName)
    {
        if (in_array($permissionName, $this->selectedPermissions)) {
            $this->role->revokePermissionTo($permissionName);
            $this->selectedPermissions = array_diff($this->selectedPermissions, [$permissionName]);
        } else {
            $this->role->givePermissionTo($permissionName);
            $this->selectedPermissions[] = $permissionName;
        }

        session()->flash('message', 'Permissions updated successfully.');
    }

    /**
     * Render the component
     */
    public function render()
    {
        $permissions = Permission::where('name', 'like', '%' . $this->search . '%')
            ->orderBy('name')
            ->get()
            ->groupBy(function($permission) {
                // Group by the first part of the permission name (e.g., 'admin', 'user', 'client')
                $parts = explode('.', $permission->name);
                return count($parts) > 1 ? $parts[0] : 'General';
            });

        return view('livewire.tech.admin.user_management.roles.role-permissions', [
            'groupedPermissions' => $permissions
        ]);
    }
}
