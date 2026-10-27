<?php

namespace App\Modules\UserManagement\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Actions\DeleteUserPermission;
use App\Modules\UserManagement\Actions\DeleteUserRole;
use App\Modules\UserManagement\Actions\SetRolePermission;
use App\Modules\UserManagement\Actions\StorePermission;
use App\Modules\UserManagement\Actions\StoreRole;
use App\Modules\UserManagement\Actions\UpdatePermission;
use App\Modules\UserManagement\Actions\UpdateRole;
use App\Modules\UserManagement\Actions\UserAuthorizationApi;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Thin adapters over the role and permission owners used by web. */
class AuthorizationApiController extends Controller
{
    public function roles(Request $request, UserAuthorizationApi $api)
    {
        return response()->json(['data' => $api->roles($request)]);
    }

    public function role(Role $role, UserAuthorizationApi $api)
    {
        abort_unless($role->guard_name === 'web', 404);

        return response()->json(['data' => $api->role($role)]);
    }

    public function permissionOptions(Request $request, Role $role, UserAuthorizationApi $api)
    {
        abort_unless($role->guard_name === 'web', 404);

        return response()->json(['data' => $api->permissions($request)]);
    }

    public function createRole(Request $request, UserAuthorizationApi $api, StoreRole $action)
    {
        return response()->json(['data' => $api->role($action->handle($api->name($request, 'roles')))], 201);
    }

    public function updateRole(Request $request, Role $role, UserAuthorizationApi $api, UpdateRole $action)
    {
        abort_unless($role->guard_name === 'web', 404);

        return response()->json(['data' => $api->role($action->handle($role, $api->name($request, 'roles', $role->id)))]);
    }

    public function deleteRole(Role $role, DeleteUserRole $action)
    {
        abort_unless($role->guard_name === 'web', 404);
        $id = $role->id;
        $action->handle($role);

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    public function permissions(Request $request, UserAuthorizationApi $api)
    {
        return response()->json(['data' => $api->permissions($request)]);
    }

    public function permission(Permission $permission, UserAuthorizationApi $api)
    {
        abort_unless($permission->guard_name === 'web', 404);

        return response()->json(['data' => $api->permission($permission)]);
    }

    public function createPermission(Request $request, UserAuthorizationApi $api, StorePermission $action)
    {
        return response()->json(['data' => $api->permission($action->handle($api->name($request, 'permissions')))], 201);
    }

    public function updatePermission(Request $request, Permission $permission, UserAuthorizationApi $api, UpdatePermission $action)
    {
        abort_unless($permission->guard_name === 'web', 404);

        return response()->json(['data' => $api->permission($action->handle($permission, $api->name($request, 'permissions', $permission->id)))]);
    }

    public function deletePermission(Permission $permission, DeleteUserPermission $action)
    {
        abort_unless($permission->guard_name === 'web', 404);
        $id = $permission->id;
        $action->handle($permission);

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    public function setPermission(Request $request, Role $role, Permission $permission, SetRolePermission $action, UserAuthorizationApi $api)
    {
        abort_unless($role->guard_name === 'web' && $permission->guard_name === 'web', 404);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return response()->json(['data' => $api->role($action->handle($role, $permission->name, (bool) $data['enabled']))]);
    }
}
