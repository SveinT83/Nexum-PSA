<?php

namespace App\Modules\Sales\Controllers\Api\V1;

use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Sales\Actions\SalesWorkflow;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuoteConversionPlan;
use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Resources\Api\V1\SalesProjection;
use Illuminate\Http\Request;

/** Every route has a fixed operation; callers cannot dispatch arbitrary methods or model classes. */
class SalesQuoteController extends SalesApiController
{
    public function quotes(SalesOpportunity $opportunity)
    {
        return response()->json(['data' => $opportunity->quotes()->orderBy('id')->get()->map->only(['id', 'quote_key', 'status', 'current_version_id'])]);
    }

    public function versions(Request $request, SalesOpportunity $opportunity)
    {
        $query = SalesQuoteVersion::query()->whereHas('quote', fn ($q) => $q->where('opportunity_id', $opportunity->id))->orderByDesc('id');

        return response()->json(SalesProjection::page($query->paginate($request->integer('per_page') ?: 25), fn ($v) => SalesProjection::version($v)));
    }

    public function show(SalesOpportunity $opportunity, SalesQuoteVersion $version)
    {
        return response()->json(['data' => SalesProjection::version($this->version($opportunity, $version))]);
    }

    public function customerPreview(SalesOpportunity $opportunity, SalesQuoteVersion $version)
    {
        return response()->json(['data' => SalesProjection::version($this->version($opportunity, $version), true)]);
    }

    public function acceptance(SalesOpportunity $opportunity, SalesQuoteVersion $version)
    {
        return response()->json(['data' => SalesProjection::acceptance($this->version($opportunity, $version))]);
    }

    public function plans(SalesOpportunity $opportunity, SalesQuoteVersion $version)
    {
        return response()->json(['data' => SalesProjection::version($this->version($opportunity, $version))['conversion_plans']]);
    }

    public function pdf(SalesOpportunity $opportunity, SalesQuoteVersion $version, \App\Modules\Sales\Queries\SalesQuotePdf $pdf)
    {
        $result = $pdf->handle($this->version($opportunity, $version));

        return response($result['content'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$result['filename'].'"'] + ($result['etag'] ? ['ETag' => '"'.$result['etag'].'"'] : []));
    }

    public function draft(Request $request, SalesOpportunity $opportunity, SalesWorkflow $workflow)
    {
        DomainApiInput::validate($request, []);
        $receipt = $this->command($request, $opportunity, function () use ($request, $opportunity, $workflow) {
            app()->call([$workflow, 'ensureQuote'], ['request' => $request, 'sale' => $opportunity]);

            return ['type' => 'quote_version', 'id' => $opportunity->fresh()->current_quote_version_id];
        });

        return response()->json(['data' => SalesProjection::version(SalesQuoteVersion::findOrFail($receipt['id'])), 'operation' => $receipt], 201);
    }

    public function details(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version)
    {
        return $this->apply($request, $opportunity, $version, 'updateQuoteDetails');
    }

    public function addLine(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version)
    {
        return $this->apply($request, $opportunity, $version, 'addQuoteLine');
    }

    public function updateLine(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version, SalesQuoteLine $line)
    {
        abort_unless((int) $line->quote_version_id === (int) $version->id, 404);

        return $this->apply($request, $opportunity, $version, 'updateQuoteLine', ['line' => $line]);
    }

    public function deleteLine(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version, int $line)
    {
        DomainApiInput::validate($request, []);
        $receipt = $this->command($request, $opportunity, function () use ($request, $opportunity, $version, $line) {
            $record = $version->lines()->findOrFail($line);
            app()->call([app(SalesWorkflow::class), 'deleteQuoteLine'], ['request' => $request, 'sale' => $opportunity, 'line' => $record]);

            return ['type' => 'quote_line', 'id' => $line, 'deleted' => true, 'version_id' => $version->id];
        }, true, $this->version($opportunity, $version));

        return response()->json(['data' => SalesProjection::version($version->refresh()), 'operation' => $receipt]);
    }

    public function revise(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version)
    {
        DomainApiInput::validate($request, []);

        return $this->apply($request, $opportunity, $version, 'reviseQuote');
    }

    public function requestApproval(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version)
    {
        DomainApiInput::validate($request, []);

        return $this->apply($request, $opportunity, $version, 'requestQuoteApproval');
    }

    public function approve(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version)
    {
        return $this->apply($request, $opportunity, $version, 'approveQuote');
    }

    public function reject(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version)
    {
        return $this->apply($request, $opportunity, $version, 'rejectQuote');
    }

    public function changes(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version)
    {
        return $this->apply($request, $opportunity, $version, 'requestQuoteChanges');
    }

    public function template(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version)
    {
        return $this->apply($request, $opportunity, $version, 'applyQuoteTemplate');
    }

    public function updatePlan(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version, SalesQuoteConversionPlan $plan)
    {
        abort_unless((int) $plan->quote_version_id === (int) $version->id, 404);

        return $this->apply($request, $opportunity, $version, 'updateConversionPlan', ['plan' => $plan], false);
    }

    private function apply(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version, string $method, array $parameters = [], bool $current = true)
    {
        $this->version($opportunity, $version);
        $receipt = $this->command($request, $opportunity, function () use ($request, $opportunity, $method, $parameters) {
            $result = app()->call([app(SalesWorkflow::class), $method], ['request' => $request, 'sale' => $opportunity] + $parameters);

            return ['type' => 'quote_version', 'id' => $opportunity->fresh()->current_quote_version_id, 'notice' => $result->level, 'message' => $result->message, 'affected' => $result->data];
        }, true, $version, $current);
        $target = $method === 'updateConversionPlan' ? $version : SalesQuoteVersion::findOrFail($receipt['id']);

        return response()->json(['data' => SalesProjection::version($target), 'operation' => $receipt]);
    }
}
