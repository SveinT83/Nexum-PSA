<?php

namespace App\Modules\Sales\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\ExecuteSalesApiCommand;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Support\SalesApiAccess;
use App\Modules\Sales\Support\SalesRevision;
use Illuminate\Http\Request;

/** Common transport boundary: current authority and parent locks precede replay and stale checks. */
abstract class SalesApiController extends Controller
{
    protected function command(Request $request, ?SalesOpportunity $opportunity, callable $apply, bool $required = true, ?SalesQuoteVersion $version = null, bool $current = true): array
    {
        $permission = $request->route()->defaults['sales_permission'] ?? 'sales.view';

        return app(ExecuteSalesApiCommand::class)->handle($request, $request->route()->getName().':'.($opportunity?->id ?? 'new'), $request->all(),
            function () use ($request, $permission, $opportunity, $version) {
                SalesApiAccess::authorize($request, $permission, $request->route()->defaults['sales_admin'] ?? false);
                if ($opportunity) {
                    SalesOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
                    $opportunity->refresh();
                }
                if ($version) {
                    SalesQuoteVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
                    $version->refresh();
                    abort_unless((int) $version->quote->opportunity_id === (int) $opportunity->id, 404);
                }
            }, function () use ($request, $opportunity, $apply, $required, $version, $current) {
                abort_if($version && $current && (int) $opportunity->current_quote_version_id !== (int) $version->id, 409, 'A newer quote version is current.');
                if ($version || $opportunity) {
                    SalesRevision::check($request, $version ?? $opportunity, $required);
                }

                return $apply();
            }, $required);
    }

    protected function version(SalesOpportunity $opportunity, SalesQuoteVersion $version): SalesQuoteVersion
    {
        abort_unless((int) $version->quote->opportunity_id === (int) $opportunity->id, 404);

        return $version;
    }
}
