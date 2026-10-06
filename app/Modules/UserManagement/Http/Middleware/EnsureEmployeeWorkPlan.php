<?php

namespace App\Modules\UserManagement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureEmployeeWorkPlan
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('workday.enabled'), 404);
        $user = $request->user();
        // Internal access and a real human are required even for wildcard tokens.
        abort_unless($user && $user->isActive() && ! $user->isSystemActor()
            && $user->roles()->exists(), 403);

        // A coordinator binding must not turn a shared workload credential into employee delegation.
        $token = $user->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            abort_if(\Illuminate\Support\Facades\DB::table('ai_workload_token_bindings')
                ->where('personal_access_token_id', $token->id)->exists(), 403,
                'Coordinator workload credentials cannot operate an employee work plan.');
        }

        return $next($request);
    }
}
