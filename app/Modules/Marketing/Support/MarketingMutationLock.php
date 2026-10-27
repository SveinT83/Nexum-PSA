<?php

namespace App\Modules\Marketing\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Browser/API writes share the same native DB mutex and refresh bound snapshots. */
class MarketingMutationLock
{
    public static function lock(Request $request): void
    {
        abort_unless(DB::table('marketing_api_mutex')->where('id', 1)->lockForUpdate()->first(), 503, 'Marketing API migration is required.');
        foreach ($request->route()->parameters() as $value) {
            if ($value instanceof Model) {
                $value->refresh();
            }
        }
    }

    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next) {
            self::lock($request);

            $response = $next($request);
            if ($response->getStatusCode() >= 400) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            }

            return $response;
        });
    }
}
