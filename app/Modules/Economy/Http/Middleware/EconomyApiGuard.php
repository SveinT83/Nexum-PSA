<?php

namespace App\Modules\Economy\Http\Middleware;

use App\Modules\Economy\Support\EconomyAuthority;
use Closure;
use Illuminate\Http\Request;

/** Re-evaluate current actor authority independently of Sanctum scope checks. */
class EconomyApiGuard
{
    public function handle(Request $request, Closure $next)
    {
        EconomyAuthority::check($request);

        return $next($request);
    }
}
