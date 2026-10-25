<?php

namespace App\Modules\Commercial\Controllers\Api\V1;

use App\Modules\Commercial\Actions\CostCatalogueWorkflow;
use App\Modules\Commercial\Actions\LegalCatalogueWorkflow;
use App\Modules\Commercial\Actions\PackageCatalogueWorkflow;
use App\Modules\Commercial\Actions\ServiceCatalogueWorkflow;
use App\Modules\Commercial\Actions\SlaCatalogueWorkflow;
use App\Modules\Commercial\Actions\TimeRateCatalogueWorkflow;
use App\Modules\Commercial\Actions\UnitCatalogueWorkflow;
use App\Modules\Commercial\Queries\CommercialCatalogueQuery;
use App\Modules\Commercial\Support\CommercialInput;
use App\Modules\Commercial\Support\CommercialProjection;
use Illuminate\Http\Request;

/** Typed transport delegates to explicit catalogue domain operations, never generic mass assignment. */
class CommercialCatalogueController extends CommercialApiController
{
    private function kind(Request $request): string
    {
        return $request->route()->defaults['commercial_kind'];
    }

    private function record(Request $request, bool $optional = false)
    {
        $class = CommercialCatalogueQuery::model($this->kind($request));
        $key = $request->route()->defaults['commercial_parameter'] ?? 'record';
        $id = $request->route($key);
        abort_unless(is_numeric($id), 404);

        return $optional ? $class::query()->find($id) : $class::query()->findOrFail($id);
    }

    private function flow(string $kind)
    {
        return app(match ($kind) {
            'services' => ServiceCatalogueWorkflow::class,'packages' => PackageCatalogueWorkflow::class,'costs' => CostCatalogueWorkflow::class,'slas' => SlaCatalogueWorkflow::class,'time-rates' => TimeRateCatalogueWorkflow::class,'terms' => LegalCatalogueWorkflow::class,'units' => UnitCatalogueWorkflow::class
        });
    }

    public function index(Request $request)
    {
        $page = app(CommercialCatalogueQuery::class)->listing($request, $this->kind($request));
        $page->setCollection($page->getCollection()->map(fn ($r) => CommercialProjection::catalogue($r)));

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()], 'links' => ['next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()]]);
    }

    public function show(Request $request)
    {
        return response()->json(['data' => CommercialProjection::catalogue($this->record($request))]);
    }

    public function context(Request $request)
    {
        $kind = $this->kind($request);
        $lookups = [];
        foreach (app(CommercialCatalogueQuery::class)->lookups($kind) as $lookup) {
            $lookups[$lookup] = url('/api/v1/commercial/'.$kind.'/context/'.$lookup);
        }

        return response()->json(['data' => ['kind' => $kind, 'lookups' => $lookups, 'revision_header' => 'If-Match', 'idempotency_header' => 'Idempotency-Key', 'rate_types' => ['labor', 'driving', 'travel', 'other'], 'rate_units' => ['hour', 'km', 'fixed'], 'billing_cycles' => ['monthly', 'yearly', 'one_time'], 'audiences' => ['all', 'business', 'private'], 'recurrences' => ['month', 'year', 'quarter', 'none']]]);
    }

    public function lookup(Request $request)
    {
        $page = app(CommercialCatalogueQuery::class)->lookup($request, $this->kind($request), $request->route('lookup'));
        $page->setCollection($page->getCollection()->map(fn ($row) => $row->attributesToArray()));

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()], 'links' => ['next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()]]);
    }

    public function previewPricing(Request $request)
    {
        if ($this->kind($request) === 'services') {
            $input = CommercialInput::validate($request, ['cost_ids' => 'sometimes|array|max:500', 'cost_ids.*' => 'integer|exists:costs,id', 'sales_price' => 'sometimes|numeric|min:0']);
            $data = app(\App\Modules\Commercial\Actions\ServiceComposition::class)->preview($input['cost_ids'] ?? [], (float) ($input['sales_price'] ?? 0));
        } else {
            $input = CommercialInput::validate($request, ['service_ids' => 'sometimes|array|max:500', 'service_ids.*' => 'integer|exists:services,id', 'custom_prices' => 'sometimes|array|max:500', 'custom_prices.*' => 'nullable|numeric|min:0']);
            $calculator = new \App\Modules\Commercial\Support\PackagePricingCalculator($input['service_ids'] ?? []);
            foreach (array_keys($input['custom_prices'] ?? []) as $unit) {
                abort_unless(ctype_digit((string) $unit) && array_key_exists((int) $unit, $calculator->pricingByUnit()), 422, 'A custom price must identify a selected Service unit.');
            }
            $data = $calculator->preview($input['custom_prices'] ?? []);
        }

        return response()->json(['data' => $data]);
    }

    public function store(Request $request)
    {
        $kind = $this->kind($request);
        $flow = $this->flow($kind);
        $result = $this->command($request, null, function () use ($request, $kind, $flow) {
            $record = $kind === 'terms' ? $flow->save($request) : $flow->store($request);

            return ['data' => CommercialProjection::catalogue($record)];
        }, $request->route()->defaults['commercial_new'] ?? true);

        return response()->json($result, 201);
    }

    public function update(Request $request)
    {
        if ($this->kind($request) === 'units' && $request->input('action') === 'delete') {
            abort_unless($request->user()->tokenCan('commercial.delete'), 403, 'Deleting a Unit also requires commercial.delete.');
        }

        $record = $this->record($request, $this->kind($request) === 'units' && $request->input('action') === 'delete');
        $kind = $this->kind($request);
        $flow = $this->flow($kind);

        return response()->json($this->command($request, $record, function () use ($request, $record, $kind, $flow) {
            abort_unless($record, 404);
            $saved = $kind === 'terms' ? $flow->save($request, $record) : $flow->update($request, $record);

            return ['data' => ($saved->exists ? CommercialProjection::catalogue($saved) : ['id' => $record->id, 'deleted' => true])];
        }, $request->route()->defaults['commercial_new'] ?? true));
    }

    public function destroy(Request $request)
    {
        CommercialInput::validate($request, []);
        $record = $this->record($request, true);
        $kind = $this->kind($request);

        return response()->json($this->command($request, $record, function () use ($record, $kind) {
            abort_unless($record, 404);
            abort_unless(in_array($kind, ['services', 'packages', 'costs', 'terms', 'units'], true), 405);
            if (method_exists($record, 'isIntegrationManaged')) {
                abort_if($record->isIntegrationManaged(), 403, 'The active source Integration owns this record.');
            }
            if ($kind === 'terms') {
                $this->flow($kind)->delete($record);
            } else {
                $record->delete();
            }

            return ['data' => ['id' => $record->id, 'deleted' => true]];
        }));
    }
}
