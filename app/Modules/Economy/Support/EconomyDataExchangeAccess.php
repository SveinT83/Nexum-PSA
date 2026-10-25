<?php

namespace App\Modules\Economy\Support;

use App\Models\Core\User;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\Integration\Support\DomainApiAuthorization;
use Illuminate\Http\Request;

/** The owner API cannot be used to bypass Economy export/run/file authorization. */
class EconomyDataExchangeAccess
{
    public static function isEconomy(DataExchangeProfile $profile): bool
    {
        return $profile->key === 'economy_orders_export' || $profile->sources()->where('source_key', 'like', 'economy.%')->exists();
    }

    public static function allowed(Request $request, DataExchangeProfile $profile, string $mode): bool
    {
        if (! self::isEconomy($profile)) {
            return true;
        }
        $actor = $request->user() ? User::query()->find($request->user()->id) : null;
        if (! $actor?->isActive()) {
            return false;
        }
        $actor->withAccessToken($request->user()->currentAccessToken());
        $request->setUserResolver(fn () => $actor);
        $permission = match ($mode) {
            'run' => 'data_exchange.run', 'download' => 'data_exchange.download', default => 'data_exchange.view'
        };
        $ability = $mode === 'run' ? 'economy.export' : 'economy.read';

        return $actor->tokenCan($ability) && app(DomainApiAuthorization::class)->permits($request, 'economy.view') && app(DomainApiAuthorization::class)->permits($request, $permission);
    }

    public static function check(Request $request, ?DataExchangeProfile $profile, string $mode): void
    {
        abort_unless($profile, 404);
        abort_unless(self::allowed($request, $profile, $mode), 403, 'Current Economy and Data Exchange authority is required.');
    }
}
