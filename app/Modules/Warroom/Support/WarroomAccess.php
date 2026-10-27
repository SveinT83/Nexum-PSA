<?php

namespace App\Modules\Warroom\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/** Warroom abilities narrow fresh native staff authority, including delegated settings managers. */
class WarroomAccess
{
    public static function allows(User $actor, string $permission): bool
    {
        return app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission);
    }

    public static function tokenHash(Request $request): string
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->getKey(), 401, 'A personal bearer token is required.');

        return hash('sha256', (string) $token->getKey());
    }

    public function handle(Request $request, Closure $next)
    {
        $old = $request->user();
        $actor = $old ? User::find($old->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists()), 403, 'An active internal actor is required.');
        $actor->withAccessToken($old->currentAccessToken());
        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);
        $defaults = $request->route()->defaults;
        abort_unless(self::allows($actor, $defaults['warroom_permission'])
            && $actor->tokenCan($defaults['warroom_ability']), 403, 'Warroom access is not permitted.');
        $allowed = $defaults['warroom_fields'] ?? [];
        $unknown = array_diff(array_keys($request->all()), $allowed);
        abort_if($unknown !== [], 422, 'Unsupported Warroom fields.');
        if ($request->isMethod('get')) {
            abort_if(! in_array($request->getContent(), ['', '[]', '{}'], true), 422, 'GET request bodies are not supported.');
        }

        try {
            $response = $next($request);
        } catch (\Illuminate\Database\QueryException|\PDOException $error) {
            return response()->json(['message' => 'Warroom data is unavailable; no successful mutation is confirmed.'], 503)
                ->header('Cache-Control', 'private, no-store');
        }
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
