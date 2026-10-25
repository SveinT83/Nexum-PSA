<?php

namespace App\Modules\Commercial\Http\Middleware;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAuthorization;
use Closure;
use Illuminate\Http\Request;

/** Token abilities are a ceiling; reload active actor authority for every request/replay. */
class CommercialApiGuard
{
    public function handle(Request $request, Closure $next)
    {
        $actor = $request->user() ? User::query()->find($request->user()->id) : null;
        abort_unless($actor && $actor->isActive(), 403, 'An active actor is required.');
        $actor->withAccessToken($request->user()->currentAccessToken());
        $request->setUserResolver(fn () => $actor);
        app(DomainApiAuthorization::class)->authorize($request, $request->route()->defaults['commercial_permission'] ?? 'commercial.view');
        if ($request->isMethod('GET')) {
            $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1', 'q' => 'sometimes|nullable|string|max:255', 'direction' => 'sometimes|in:asc,desc']);
        }

        return $next($request);
    }
}
