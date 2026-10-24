<?php

namespace App\Modules\Sales\Http\Middleware;

use App\Modules\Sales\Support\SalesApiAccess;
use Closure;
use Illuminate\Http\Request;

/** All staff routes re-evaluate actor rights even when the presented token has a wildcard. */
class SalesApiGuard
{
    public function handle(Request $request, Closure $next)
    {
        SalesApiAccess::authorize($request, $request->route()->defaults['sales_permission'] ?? 'sales.view', $request->route()->defaults['sales_admin'] ?? false);
        if ($request->isMethod('GET')) {
            $request->validate(['client_page' => 'sometimes|integer|min:1', 'owner_page' => 'sometimes|integer|min:1', 'site_page' => 'sometimes|integer|min:1', 'contact_page' => 'sometimes|integer|min:1', 'template_page' => 'sometimes|integer|min:1', 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'q' => ['sometimes', 'nullable', 'string', 'max:255']]);
        }

        return $next($request);
    }
}
