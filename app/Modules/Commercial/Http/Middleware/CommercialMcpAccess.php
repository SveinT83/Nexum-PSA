<?php

namespace App\Modules\Commercial\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/** Only a personal API token may enter the staff transport; browser sessions cannot authorize a tool call. */
final class CommercialMcpAccess
{
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== rtrim(config('app.url'), '/')) {
            return response()->json(['message' => 'Invalid request origin.'], 403);
        }
        Auth::shouldUse('sanctum');
        $actor = $request->user();
        if (! $request->bearerToken() || ! ($actor?->currentAccessToken() instanceof PersonalAccessToken)) {
            return response()->json(['message' => 'A personal Nexum bearer token is required.'], 401)
                ->header('WWW-Authenticate', 'Bearer realm="Nexum Commercial"');
        }
        if (! $actor->isActive() || $actor->isSystemActor()) {
            return response()->json(['message' => 'An active employee account is required.'], 403);
        }

        return $next($request)->header('Cache-Control', 'private, no-store')->header('Vary', 'Authorization');
    }
}
