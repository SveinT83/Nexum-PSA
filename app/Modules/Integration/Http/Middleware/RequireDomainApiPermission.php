<?php

namespace App\Modules\Integration\Http\Middleware;

use App\Modules\Integration\Support\DomainApiAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sanctum abilities are checked separately; no token can replace its actor's browser rights. */
final class RequireDomainApiPermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        app(DomainApiAuthorization::class)->authorize($request, $permissions);

        return $next($request);
    }
}
