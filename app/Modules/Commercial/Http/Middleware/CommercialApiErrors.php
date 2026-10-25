<?php

namespace App\Modules\Commercial\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Domain refusals are conflicts; constraint failures do not disclose database internals. */
class CommercialApiErrors
{
    public function handle(Request $request, Closure $next)
    {
        try {
            return $next($request);
        } catch (\DomainException|\UnexpectedValueException $error) {
            abort(409, $error->getMessage());
        } catch (\Illuminate\Database\QueryException $error) {
            if (str_starts_with((string) $error->getCode(), '23')) {
                abort(409, 'This operation conflicts with related records.');
            }
            throw $error;
        }
    }
}
