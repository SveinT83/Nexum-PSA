<?php

namespace App\Modules\UserManagement\Actions;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Queries and mutations for the existing role/permission management surfaces. */
class UserAuthorizationApi
{
    public function role(Role $role): array
    {
        return ['id' => $role->id, 'name' => $role->name, 'guard_name' => $role->guard_name,
            'permissions' => $role->permissions()->orderBy('name')->get(['permissions.id', 'name', 'guard_name'])->toArray(),
            'users_count' => \Illuminate\Support\Facades\DB::table('model_has_roles')->where('role_id', $role->id)
                ->where('model_type', \App\Models\Core\User::class)->count()];
    }

    public function permission(Permission $permission): array
    {
        return ['id' => $permission->id, 'name' => $permission->name, 'guard_name' => $permission->guard_name];
    }

    public function name(Request $request, string $table, ?int $id = null): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique($table, 'name')->ignore($id)]]);
    }

    public function roles(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        return Role::where('guard_name', 'web')->when($data['q'] ?? null,
            fn ($q, $search) => $q->where('name', 'like', '%'.$search.'%'))->orderBy('name')->get()
            ->map(fn ($role) => $this->role($role))->all();
    }

    public function permissions(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        return Permission::where('guard_name', 'web')->when($data['q'] ?? null,
            fn ($q, $search) => $q->where('name', 'like', '%'.$search.'%'))->orderBy('name')->get()
            ->map(fn ($permission) => $this->permission($permission))->all();
    }
}
