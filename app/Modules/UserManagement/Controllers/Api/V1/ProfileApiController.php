<?php

namespace App\Modules\UserManagement\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Actions\UserProfileApi;
use App\Modules\UserManagement\Support\UserManagementApiAccess;
use App\Modules\UserManagement\Support\UserProfileData;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/** Typed projections of the current profile shell; no future integration promises. */
class ProfileApiController extends Controller
{
    public function show(Request $request, UserProfileApi $profiles)
    {
        return response()->json(['data' => $profiles->profile($request->user())]);
    }

    public function update(Request $request, UserProfileApi $profiles)
    {
        return response()->json(['data' => $profiles->update($request)]);
    }

    public function preferences(Request $request, UserProfileApi $profiles)
    {
        return response()->json(['data' => $profiles->preferences($request->user())]);
    }

    public function updatePreferences(Request $request, UserProfileApi $profiles)
    {
        return response()->json(['data' => $profiles->updatePreferences($request)]);
    }

    public function context(Request $request)
    {
        $permissions = collect(['user.view', 'user.create', 'user.update', 'user.manage_roles', 'user.manage_permissions', 'user.invite', 'user.manage_2fa'])
            ->mapWithKeys(fn ($permission) => [$permission => UserManagementApiAccess::allows($request->user(), $permission)])->all();

        return response()->json(['data' => ['capabilities' => $permissions,
            'statuses' => ['PENDING_INVITE', 'ACTIVE', 'DISABLED'],
            'working_hours' => UserProfileData::defaultWorkingHours(), 'timezone' => config('app.timezone', 'UTC'),
            'roles' => $permissions['user.manage_roles']
                ? Role::where('guard_name', 'web')->orderBy('name')->get(['id', 'name', 'guard_name'])->toArray() : [],
            'security_session_required' => true]]);
    }

    public function shell()
    {
        return response()->json(['data' => ['personal_integrations' => [],
            'integrations_status' => 'not_configured', 'view_status' => 'placeholder',
            'preferences_url' => route('api.v1.users.me.preferences'),
            'notification_settings_owner' => 'Notification', 'api_tokens_owner' => 'Integration']]);
    }
}
