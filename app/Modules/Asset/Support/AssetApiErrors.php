<?php

namespace App\Modules\Asset\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class AssetApiErrors
{
    public function handle(Request $request, Closure $next)
    {
        try {
            return $next($request);
        } catch (QueryException $error) {
            $code = $error->errorInfo[1] ?? null;
            if (str_starts_with((string) $error->getCode(), '23') || in_array($code, [1205, 1213], true)) {
                abort(409, 'Asset data changed concurrently; reload before retrying.');
            }
            abort(503, 'Asset storage is unavailable; verify the command migration and database.');
        }
    }
}
