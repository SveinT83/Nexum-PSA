<?php

namespace App\Modules\Clients\Http\Middleware;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAuthorization;
use Closure;
use Illuminate\Http\Request;

/** Token grants and the freshly loaded actor's browser rights are independent. */
class ClientApiGuard
{
    public static function authorize(Request $request): void
    {
        $previous = $request->user();
        $actor = $previous ? User::query()->find($previous->id) : null;
        abort_unless($actor && $actor->isActive(), 403, 'An active actor is required.');
        $actor->withAccessToken($previous->currentAccessToken());
        $request->setUserResolver(fn () => $actor);
        foreach ($request->route()->defaults['client_permissions'] ?? ['client.view'] as $permission) {
            app(DomainApiAuthorization::class)->authorize($request, $permission);
        }

    }

    public function handle(Request $request, Closure $next)
    {
        self::authorize($request);

        return $next($request);
    }
}
