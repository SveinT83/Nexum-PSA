<?php

namespace App\Modules\Risk\Http\Middleware;

use App\Modules\Risk\Support\RiskPolicy;
use Closure;
use Illuminate\Http\Request;

/** Scopes remain independent from freshly loaded actor rights. */
final class RiskApiGuard
{
    public static function authorize(Request $request): void
    {
        $old = $request->user();
        if ($old) {
            \Illuminate\Support\Facades\Auth::setUser($old);
        }
        $actor = RiskPolicy::actor();
        $request->setUserResolver(fn () => $actor);
        foreach ($request->route()->defaults['risk_permissions'] ?? [] as $p) {
            RiskPolicy::actor($p);
        }
    }

    public function handle(Request $request, Closure $next)
    {
        self::authorize($request);

        return $next($request);
    }
}
