<?php

namespace App\Modules\Report\Support;

use App\Models\Core\User;
use App\Modules\UserManagement\Support\UserManagementApiAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/** Identified employee reports are personal reads, never a coordinator workload profile. */
class TimeReportAccess
{
    public const PERMISSIONS = ['report.view', 'ticket.view', 'task.view', 'client.view'];

    public function visible(User $user): bool
    {
        if (! $user->isActive() || $user->isSystemActor()
            || (! $user->roles()->exists() && ! $user->permissions()->exists())) {
            return false;
        }
        foreach (self::PERMISSIONS as $permission) {
            if (! UserManagementApiAccess::allows($user, $permission)) {
                return false;
            }
        }
        $token = $user->currentAccessToken();

        return ! ($token instanceof PersonalAccessToken
            && DB::table('ai_workload_token_bindings')->where('personal_access_token_id', $token->id)->exists());
    }

    public function authorize(Request $request): User
    {
        $actor = UserManagementApiAccess::actor($request);
        abort_unless($this->visible($actor), 403);

        return $actor;
    }
}
