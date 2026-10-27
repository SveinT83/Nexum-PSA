<?php

namespace App\Modules\Booking\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/** Storage failures never expose SQL, bindings, contacts or provider exception bodies. */
class BookingApiErrors
{
    public function handle(Request $request, Closure $next)
    {
        try {
            return $next($request);
        } catch (QueryException $error) {
            $code = $error->errorInfo[1] ?? null;
            if (str_starts_with((string) $error->getCode(), '23') || in_array($code, [1205, 1213], true)) {
                abort(409, 'Booking changed concurrently; reload before retrying.');
            }
            abort(503, 'Booking storage is unavailable; verify its migration and database.');
        }
    }
}
