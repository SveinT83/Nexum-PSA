<?php

namespace App\Modules\Sales\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\ExecuteSalesApiCommand;
use App\Modules\Sales\Actions\RecordSalesQuoteView;
use App\Modules\Sales\Actions\SalesPortalWorkflow;
use App\Modules\Sales\Actions\SalesPublicWorkflow;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Queries\SalesQuotePdf;
use App\Modules\Sales\Resources\Api\V1\SalesProjection;
use App\Modules\Sales\Support\PortalSalesQuoteAccess;
use Illuminate\Http\Request;

/** Staff tokens cannot choose customer identity; portal context or exact quote capability is required. */
class SalesCustomerQuoteController extends Controller
{
    private function resolve(Request $request): SalesQuoteVersion
    {
        if ($request->route('token') !== null) {
            $token = (string) $request->route('token');
            $request->attributes->set('sales_public_token_hash', hash('sha256', $token));
            $request->setUserResolver(fn () => null);

            return SalesQuoteVersion::query()->where('secure_token', $token)->firstOrFail();
        }
        $version = SalesQuoteVersion::query()->findOrFail($request->route('version'));
        $context = $request->attributes->get('customerPortalContext');
        abort_unless($context && app(PortalSalesQuoteAccess::class)->canView($context, $version), 404);

        return $version;
    }

    public function index(Request $request, PortalSalesQuoteAccess $access)
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return response()->json(SalesProjection::page($access->visibleQuoteVersions($request->attributes->get('customerPortalContext'))->latest('sent_at')->paginate($request->integer('per_page') ?: 15), fn ($row) => SalesProjection::version($row, true)));
    }

    public function show(Request $request)
    {
        return response()->json(['data' => SalesProjection::version($this->resolve($request), true)]);
    }

    public function pdf(Request $request, SalesQuotePdf $renderer)
    {
        $result = $renderer->handle($this->resolve($request));

        return response($result['content'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$result['filename'].'"'] + ($result['etag'] ? ['ETag' => '"'.$result['etag'].'"'] : []));
    }

    public function viewed(Request $request)
    {
        return $this->respond($request, 'viewed');
    }

    public function accept(Request $request)
    {
        return $this->respond($request, 'accept');
    }

    public function decline(Request $request)
    {
        return $this->respond($request, 'decline');
    }

    public function question(Request $request)
    {
        return $this->respond($request, 'question');
    }

    private function respond(Request $request, string $operation)
    {
        $version = $this->resolve($request);
        $context = $request->attributes->get('customerPortalContext');
        $receipt = app(ExecuteSalesApiCommand::class)->handle($request, 'sales.customer.'.$operation.':'.$version->id, $request->all(),
            function () use ($request, $version) {
                // Same opportunity-before-version lock order as staff mutation/acceptance.
                SalesOpportunity::query()->whereKey($version->quote->opportunity_id)->lockForUpdate()->firstOrFail();
                SalesQuoteVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
                $this->resolve($request);
            }, function () use ($request, $version, $context, $operation) {
                if ($operation === 'viewed') {
                    app(RecordSalesQuoteView::class)->handle($version, $context);

                    return ['type' => 'quote_version', 'id' => $version->id, 'notice' => 'success'];
                }
                $workflow = $context ? app(SalesPortalWorkflow::class) : app(SalesPublicWorkflow::class);
                $params = ['request' => $request] + ($context ? ['quote' => $version->fresh()] : ['token' => (string) $request->route('token')]);
                $result = app()->call([$workflow, $operation], $params);

                return ['type' => 'quote_version', 'id' => $version->id, 'notice' => $result->level, 'message' => $result->message];
            });

        return response()->json(['data' => SalesProjection::version($version->fresh(), true), 'operation' => $receipt], ($receipt['notice'] ?? '') === 'error' ? 422 : 200);
    }
}
