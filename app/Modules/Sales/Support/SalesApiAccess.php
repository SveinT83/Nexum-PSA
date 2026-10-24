<?php

namespace App\Modules\Sales\Support;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAuthorization;
use Illuminate\Http\Request;

/** A token is only a ceiling. Match the current browser's role and domain permission rules. */
final class SalesApiAccess
{
    public static function authorize(Request $request, string $permission = 'sales.view', bool $admin = false): User
    {
        $actor = $request->user() ? User::query()->find($request->user()->id) : null;
        abort_unless($actor && $actor->isActive(), 403, 'An active actor is required.');
        if ($request->user()->currentAccessToken()) {
            $actor->withAccessToken($request->user()->currentAccessToken());
        }
        $request->setUserResolver(fn () => $actor);
        app(DomainApiAuthorization::class)->authorize($request, $permission);

        // AdminAccess accepts active accounts with current role/direct permissions; the named settings permission is authoritative.
        return $actor;
    }

    public static function actorKey(Request $request): string
    {
        $portal = $request->attributes->get('customerPortalContext');
        $identity = $portal ? 'portal:'.$portal->account->id.':'.$portal->membership->id.':'.$portal->contact->id
            : ($request->attributes->has('sales_public_token_hash') ? 'public:'.$request->attributes->get('sales_public_token_hash') : 'staff:'.$request->user()?->id);

        return hash('sha256', $identity);
    }
}
