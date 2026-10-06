<?php

namespace App\Modules\Integration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Telescope\Telescope;

final class ProtectTripletexCredentials
{
    public function handle(Request $request, Closure $next)
    {
        // Exclude whole request/session/provider responses, not just a named password field.
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
