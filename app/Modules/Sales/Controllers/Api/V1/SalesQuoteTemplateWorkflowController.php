<?php

namespace App\Modules\Sales\Controllers\Api\V1;

use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Sales\Actions\ExecuteSalesApiCommand;
use App\Modules\Sales\Models\SalesQuoteTemplate;
use App\Modules\Sales\Services\SalesQuoteTemplateWorkflowService;
use App\Modules\Sales\Support\SalesApiAccess;
use App\Modules\Sales\Support\SalesRevision;
use Illuminate\Http\Request;

/** Existing template contracts retain paths/statuses; actor rights and durable result identity are added. */
class SalesQuoteTemplateWorkflowController extends SalesApiController
{
    public function catalog(SalesQuoteTemplateWorkflowService $workflow)
    {
        return response()->json(['data' => $workflow->catalog()]);
    }

    public function index(Request $request)
    {
        $query = SalesQuoteTemplate::query()->withCount(['optionGroups', 'lines', 'acknowledgements'])->orderByDesc('is_active')->orderBy('name');

        return response()->json(\App\Modules\Sales\Resources\Api\V1\SalesProjection::page($query->paginate($request->integer('per_page') ?: 50), fn ($t) => $t->only(['id', 'template_key', 'name', 'description', 'is_active', 'target_type', 'customer_segment', 'lines_count', 'option_groups_count', 'acknowledgements_count', 'updated_at']) + ['revision' => SalesRevision::of($t)]));
    }

    public function show(SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow)
    {
        return response()->json(['data' => $workflow->templateResource($template) + ['revision' => SalesRevision::of($template)]]);
    }

    public function store(Request $request, SalesQuoteTemplateWorkflowService $workflow)
    {
        $data = DomainApiInput::validate($request, $workflow->templateRules());
        $receipt = $this->mutation($request, null, fn () => ['type' => 'template', 'id' => $workflow->createTemplate($data, $request->user())->id]);

        return response()->json(['data' => $workflow->templateResource(SalesQuoteTemplate::findOrFail($receipt['id'])), 'operation' => $receipt], 201);
    }

    public function update(Request $request, SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow)
    {
        $data = DomainApiInput::validate($request, $workflow->templateRules());
        $receipt = $this->mutation($request, $template->id, fn ($root) => ['type' => 'template', 'id' => $workflow->updateTemplate($root, $data, $request->user())->id]);

        return response()->json(['data' => $workflow->templateResource($template->refresh()) + ['revision' => SalesRevision::of($template)], 'operation' => $receipt]);
    }

    public function destroy(Request $request, int $template)
    {
        DomainApiInput::validate($request, []);
        $this->mutation($request, $template, function ($root) {
            $root->delete();

            return ['type' => 'template', 'id' => $root->id, 'deleted' => true];
        });

        return response()->noContent();
    }

    public function storeLine(Request $request, SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow)
    {
        $data = DomainApiInput::validate($request, $workflow->lineRules($template));
        $receipt = $this->mutation($request, $template->id, fn ($root) => ['type' => 'template_line', 'id' => $workflow->createLine($root, $data)->id]);

        return response()->json(['data' => $workflow->lineResource($template->lines()->findOrFail($receipt['id'])->load('optionGroup')), 'operation' => $receipt], 201);
    }

    public function destroyLine(Request $request, int $template, int $line)
    {
        DomainApiInput::validate($request, []);
        $this->mutation($request, $template, function ($root) use ($line) {
            $record = $root->lines()->findOrFail($line);
            $record->delete();

            return ['type' => 'template_line', 'id' => $line, 'deleted' => true];
        });

        return response()->noContent();
    }

    public function storeAcknowledgement(Request $request, SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow)
    {
        $data = DomainApiInput::validate($request, $workflow->acknowledgementRules($template));
        $receipt = $this->mutation($request, $template->id, fn ($root) => ['type' => 'template_acknowledgement', 'id' => $workflow->createAcknowledgement($root, $data)->id]);

        return response()->json(['data' => $workflow->acknowledgementResource($template->acknowledgements()->findOrFail($receipt['id'])), 'operation' => $receipt], 201);
    }

    public function destroyAcknowledgement(Request $request, int $template, int $acknowledgement)
    {
        DomainApiInput::validate($request, []);
        $this->mutation($request, $template, function ($root) use ($acknowledgement) {
            $record = $root->acknowledgements()->findOrFail($acknowledgement);
            $record->delete();

            return ['type' => 'template_acknowledgement', 'id' => $acknowledgement, 'deleted' => true];
        });

        return response()->noContent();
    }

    private function mutation(Request $request, ?int $id, callable $apply): array
    {
        $root = null;

        return app(ExecuteSalesApiCommand::class)->handle($request, $request->route()->getName().':'.($id ?? 'new'), $request->all(),
            function () use ($request, $id, &$root) {
                SalesApiAccess::authorize($request, 'sales.manage_settings', true);
                if ($id) {
                    $root = SalesQuoteTemplate::withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
                }
            }, function () use ($request, &$root, $apply) {
                abort_if($root?->trashed(), 404);
                if ($root) {
                    SalesRevision::check($request, $root, false);
                }

                return $apply($root);
            }, false);
    }
}
