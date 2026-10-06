<?php

namespace App\Modules\Workday\Support;

use App\Models\Core\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class WorkdayAccess
{
    public function authorize(User $user, string $permission, bool $settings = false): void
    {
        abort_unless($settings || app(WorkdaySettings::class)->enabled(), 404);
        abort_unless($user->isActive() && ! $user->isSystemActor(), 403);
        // Explicit employee permissions also support future custom internal roles.
        abort_unless($user->hasPermissionTo('workday.'.$permission, 'web'), 403);
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            abort_if(DB::table('ai_workload_token_bindings')->where('personal_access_token_id', $token->id)->exists(), 403,
                'Coordinator workload credentials cannot operate an employee workday.');
        }
    }

    /** Discovery uses the same explicit gate; Superuser's global Gate bypass is deliberately not used. */
    public function canViewOverview(User $user): bool
    {
        if (! app(WorkdaySettings::class)->enabled() || ! $user->isActive() || $user->isSystemActor()
            || ! $user->hasPermissionTo('workday.view_all', 'web')) {
            return false;
        }
        $token = $user->currentAccessToken();

        return ! ($token instanceof PersonalAccessToken
            && DB::table('ai_workload_token_bindings')->where('personal_access_token_id', $token->id)->exists());
    }
}
