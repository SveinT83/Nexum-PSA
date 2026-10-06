<?php

namespace App\Modules\Workday\Middleware;

use App\Modules\Workday\Support\WorkdayPrivacy;
use Closure;
use Illuminate\Http\Request;

class PreventWorkdayCopies
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (WorkdayPrivacy::matches($request)) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
