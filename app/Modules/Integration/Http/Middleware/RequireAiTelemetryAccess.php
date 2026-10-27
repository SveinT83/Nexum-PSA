<?php

namespace App\Modules\Integration\Http\Middleware;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAuthorization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

/** Read scope cannot replace current native AI administration authority. */
final class RequireAiTelemetryAccess
{
    public function handle(Request $request, Closure $next)
    {
        $old = $request->user();
        $actor = $old instanceof User ? User::find($old->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor(), 403, 'Active internal AI administration is required.');
        $token = $old->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->getKey()) {
            $token = PersonalAccessToken::find($token->getKey());
            abort_unless($token && $token->tokenable_type === User::class && (int) $token->tokenable_id === $actor->id
                && (! $token->expires_at || $token->expires_at->isFuture()), 401, 'Token unavailable.');
        }
        abort_unless($token instanceof PersonalAccessToken || ($token instanceof TransientToken && $request->hasSession()), 401, 'Authentication unavailable.');
        $actor->withAccessToken($token);
        $request->setUserResolver(fn () => $actor);
        Auth::setUser($actor);
        abort_unless($actor->tokenCan('integration.ai_telemetry.read'), 403, 'Missing AI telemetry read scope.');
        app(DomainApiAuthorization::class)->authorize($request, 'integration.ai_manage');
        $extra = array_diff(array_keys($request->query()), $request->route()->defaults['ai_telemetry_fields'] ?? []);
        abort_if($extra !== [], 422, 'Unsupported AI telemetry query fields.');

        return $next($request);
    }
}
