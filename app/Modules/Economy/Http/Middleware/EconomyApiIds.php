<?php

namespace App\Modules\Economy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Refuse malformed/overflowing identities before PHP's scalar controller dispatch can throw. */
class EconomyApiIds
{
    public function handle(Request $request, Closure $next)
    {
        foreach (['order', 'line', 'operation', 'run'] as $name) {
            $id = $request->route()->parameter($name);
            if ($id !== null) {
                abort_if(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false, 404);
            }
        }

        return $next($request);
    }
}
