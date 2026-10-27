<?php

namespace App\Modules\UserManagement\Support;

use App\Http\Middleware\RequireTwoFactor;
use Closure;
use Illuminate\Http\Request;

/** First-party sessions retain web enrollment policy; bearer auth remains Integration-owned. */
class UserManagementApiMfa
{
    public static function authorize(Request $request): void
    {
        $token = $request->user()?->currentAccessToken();
        // Sanctum chooses session authentication before a supplied bearer header. Check the
        // authenticated credential, so a fake header cannot bypass an incomplete web login.
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->exists && $token->getKey()) {
            return;
        }
        if ($request->routeIs('api.v1.users.security', 'api.v1.users.security.*')) {
            return;
        }
        abort_if(app(RequireTwoFactor::class)->requiresEnrollment($request), 403,
            'Two-factor authentication enrollment is required for your role.');
    }

    public function handle(Request $request, Closure $next)
    {
        self::authorize($request);

        return $next($request);
    }
}
