<?php

namespace App\Modules\DataExchange\Support;

use App\Models\Core\User;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\Economy\Support\EconomyDataExchangeAccess;
use App\Modules\Integration\Support\DomainApiAuthorization;
use Illuminate\Http\Request;

/** Token grants, current actor permissions and source ownership are independent requirements. */
final class DataExchangeAuthority
{
    public static function actor(Request $request, string $permission): User
    {
        $previous = $request->user();
        $actor = $previous ? User::query()->find($previous->id) : null;
        abort_unless($actor?->isActive(), 403, 'An active current actor is required.');
        $actor->withAccessToken($previous->currentAccessToken());
        $request->setUserResolver(fn () => $actor);
        app(DomainApiAuthorization::class)->authorize($request, $permission);

        return $actor;
    }

    public static function permits(?User $actor, string $permission): bool
    {
        return $actor?->isActive() && app(\App\Http\Middleware\EnforceTechRoutePermission::class)->allowsActor($actor, $permission);
    }

    public static function source(string $key, ?User $actor): DataExchangeSourceDefinition
    {
        $source = app(DataExchangeSourceRegistry::class)->get($key);
        abort_unless($source, 422, 'Unknown registered source.');
        abort_unless($actor?->isActive() && (! $source->permission || self::permits($actor, $source->permission)), 403, 'Current source authority is required.');

        return $source;
    }

    public static function profile(Request $request, ?DataExchangeProfile $profile, string $mode = 'read'): void
    {
        abort_unless($profile, 404);
        $permission = match ($mode) {
            'read' => 'view', 'download' => 'download', default => $mode,
        };
        self::actor($request, 'data_exchange.'.$permission);
        foreach ($profile->sources()->pluck('source_key') as $key) {
            self::source($key, $request->user());
        }
        EconomyDataExchangeAccess::check($request, $profile, in_array($mode, ['read', 'download'], true) ? $mode : 'run');
    }

    public static function allowed(Request $request, ?DataExchangeProfile $profile, string $mode = 'read'): bool
    {
        try {
            self::profile($request, $profile, $mode);

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404, 422], true)) {
                return false;
            }
            throw $error;
        }
    }

    public static function runtime(DataExchangeProfile $profile, ?User $actor, string $permission): void
    {
        $actor = $actor ? User::query()->find($actor->id) : null;
        abort_unless($actor?->isActive() && app(\App\Http\Middleware\EnforceTechRoutePermission::class)->allowsActor($actor, 'data_exchange.'.$permission), 403, 'Current runtime actor authority is required.');
        if (EconomyDataExchangeAccess::isEconomy($profile)) {
            abort_unless(app(\App\Http\Middleware\EnforceTechRoutePermission::class)->allowsActor($actor, 'economy.view'), 403, 'Current Economy authority is required.');
        }
        foreach ($profile->sources()->pluck('source_key') as $key) {
            self::source($key, $actor);
        }
    }
}
