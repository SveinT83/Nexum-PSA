<?php

namespace App\Modules\Risk\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/** Expose useful conflicts without leaking database queries or private record values. */
class RiskApiErrors
{
    public function handle(Request $request, Closure $next)
    {
        try {
            return $next($request);
        } catch (QueryException $error) {
            if (str_starts_with((string) $error->getCode(), '23') || in_array($error->errorInfo[1] ?? null, [1205, 1213], true)) {
                abort(409, 'Risk records changed concurrently; reload before retrying.');
            }
            \Illuminate\Support\Facades\Log::warning('Risk persistence failed.', ['sqlstate' => (string) $error->getCode(), 'database_code' => $error->errorInfo[1] ?? null]);
            abort(503, 'Risk storage is unavailable; contact the deployment owner.');
        }
    }
}
