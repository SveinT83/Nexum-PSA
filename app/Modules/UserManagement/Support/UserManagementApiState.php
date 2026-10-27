<?php

namespace App\Modules\UserManagement\Support;

use App\Models\Core\User;
use App\Modules\UserManagement\Models\UserPreference;
use App\Modules\UserManagement\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Opaque revisions include owned state, never a digest of passwords or factor material. */
class UserManagementApiState
{
    public static function etag(Request $request): ?string
    {
        $type = $request->route()->defaults['um_target'] ?? null;
        $state = match ($type) {
            'user' => self::user((int) $request->route('user')?->id),
            'me' => self::user($request->user()->id),
            'preferences' => UserPreference::where('user_id', $request->user()->id)->first()?->getRawOriginal() ?? [],
            'role' => self::role($request->route('role')),
            'permission' => Permission::find($request->route('permission')?->id)?->getRawOriginal(),
            'policy' => DB::table('common_settings')->whereIn('name', ['enforce_two_factor', 'enforce_two_factor_roles'])->orderBy('name')->get()->toArray(),
            default => null,
        };

        return $state === null ? null : '"'.hash_hmac('sha256', json_encode($state, JSON_THROW_ON_ERROR), config('app.key')).'"';
    }

    private static function user(int $id): array
    {
        $user = User::findOrFail($id);

        return [$user->only(['id', 'name', 'email', 'status', 'phone_work', 'phone_private', 'auth_security_epoch', 'vault_authority_epoch', 'updated_at']),
            UserProfile::where('user_id', $id)->first()?->getRawOriginal(),
            UserPreference::where('user_id', $id)->first()?->getRawOriginal(),
            $user->roles()->orderBy('roles.id')->pluck('roles.id')->all()];
    }

    private static function role(?Role $role): ?array
    {
        if (! $role || ! $role->exists) {
            return null;
        }
        $current = Role::find($role->id);

        return $current ? [$current->getRawOriginal(), $current->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all()] : null;
    }
}
