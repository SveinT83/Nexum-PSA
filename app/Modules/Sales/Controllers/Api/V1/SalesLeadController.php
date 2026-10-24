<?php

namespace App\Modules\Sales\Controllers\Api\V1;

use App\Models\Clients\Client;
use App\Modules\Sales\Actions\EnsureSalesDefaults;
use App\Modules\Sales\Actions\SalesLeadWorkflow;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Resources\Api\V1\SalesProjection;
use App\Modules\Sales\Support\SalesRevision;
use Illuminate\Http\Request;

/** Uses the same lead eligibility/filter/group/marketing and classification engine as the browser. */
class SalesLeadController extends SalesApiController
{
    public function index(Request $request, SalesLeadWorkflow $workflow)
    {
        $payload = $workflow->index($request, app(EnsureSalesDefaults::class));
        $result = SalesProjection::page($payload['clients'], fn ($client) => SalesProjection::client($client) + ['revision' => SalesRevision::of($client)]);
        $result['groups'] = collect($payload['groupedClients'])->map(fn ($clients) => $clients->pluck('id')->values()->all());

        return response()->json($result);
    }

    public function context(Request $request, SalesLeadWorkflow $workflow)
    {
        $data = $workflow->index($request, app(EnsureSalesDefaults::class));

        return response()->json(['data' => \Illuminate\Support\Arr::only($data, ['groupBy', 'categories', 'tags', 'classifyCategories', 'classifyTags', 'owners', 'types', 'statuses', 'nextActions', 'filters'])]);
    }

    public function show(Client $lead)
    {
        return response()->json(['data' => SalesProjection::client($lead) + ['revision' => SalesRevision::of($lead)]]);
    }

    public function classify(Request $request, Client $lead, SalesLeadWorkflow $workflow)
    {
        $receipt = app(\App\Modules\Sales\Actions\ExecuteSalesApiCommand::class)->handle($request, 'sales.lead.classify:'.$lead->id, $request->all(), function () use ($request, $lead) {
            \App\Modules\Sales\Support\SalesApiAccess::authorize($request, 'sales.lead_manage');
            Client::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $lead->refresh();
        }, function () use ($request, $lead, $workflow) {
            SalesRevision::check($request, $lead);
            $workflow->updateClassification($request, $lead);

            return ['type' => 'lead', 'id' => $lead->id];
        });

        return response()->json(['data' => SalesProjection::client($lead->refresh()) + ['revision' => SalesRevision::of($lead)], 'operation' => $receipt]);
    }

    public function start(Request $request, Client $lead, SalesLeadWorkflow $workflow)
    {
        $receipt = $this->command($request, null, fn () => ['type' => 'opportunity', 'id' => $workflow->start($request, $lead, app(\App\Modules\Sales\Actions\StoreSalesOpportunity::class))->id]);

        return response()->json(['data' => SalesProjection::opportunity(SalesOpportunity::findOrFail($receipt['id'])), 'operation' => $receipt], 201);
    }
}
