<?php

namespace App\Modules\Sales\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\EnsureSalesDefaults;
use App\Modules\Sales\Models\SalesQuoteTemplate;
use App\Modules\Sales\Models\SalesQuoteTemplateAcknowledgement;
use App\Modules\Sales\Models\SalesQuoteTemplateLine;
use App\Modules\Sales\Services\SalesQuoteTemplateWorkflowService;
use Illuminate\Http\Request;

class SalesQuoteTemplateWorkflowController extends Controller
{
    public function catalog(EnsureSalesDefaults $defaults, SalesQuoteTemplateWorkflowService $workflow)
    {
        $defaults->handle();

        return response()->json(['data' => $workflow->catalog()]);
    }

    public function index(EnsureSalesDefaults $defaults)
    {
        $defaults->handle();

        return response()->json(['data' => SalesQuoteTemplate::query()
            ->withCount(['optionGroups', 'lines', 'acknowledgements'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (SalesQuoteTemplate $template): array => [
                'id' => $template->id,
                'template_key' => $template->template_key,
                'name' => $template->name,
                'description' => $template->description,
                'is_active' => $template->is_active,
                'target_type' => $template->target_type,
                'customer_segment' => $template->customer_segment,
                'lines_count' => $template->lines_count,
                'option_groups_count' => $template->option_groups_count,
                'acknowledgements_count' => $template->acknowledgements_count,
                'updated_at' => $template->updated_at?->toISOString(),
            ])
            ->values()]);
    }

    public function show(SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow)
    {
        return response()->json(['data' => $workflow->templateResource($template)]);
    }

    public function store(Request $request, EnsureSalesDefaults $defaults, SalesQuoteTemplateWorkflowService $workflow)
    {
        $defaults->handle();

        $template = $workflow->createTemplate($request->validate($workflow->templateRules()), $request->user());

        return response()->json(['data' => $workflow->templateResource($template)], 201);
    }

    public function update(Request $request, SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow)
    {
        $template = $workflow->updateTemplate($template, $request->validate($workflow->templateRules()), $request->user());

        return response()->json(['data' => $workflow->templateResource($template)]);
    }

    public function destroy(SalesQuoteTemplate $template)
    {
        $template->delete();

        return response()->noContent();
    }

    public function storeLine(Request $request, SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow)
    {
        $line = $workflow->createLine($template, $request->validate($workflow->lineRules($template)));
        $line->load('optionGroup');

        return response()->json(['data' => $workflow->lineResource($line)], 201);
    }

    public function destroyLine(SalesQuoteTemplate $template, SalesQuoteTemplateLine $line)
    {
        abort_unless((int) $line->template_id === (int) $template->id, 404);

        $line->delete();

        return response()->noContent();
    }

    public function storeAcknowledgement(Request $request, SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow)
    {
        $acknowledgement = $workflow->createAcknowledgement($template, $request->validate($workflow->acknowledgementRules($template)));
        $acknowledgement->load('line');

        return response()->json(['data' => $workflow->acknowledgementResource($acknowledgement)], 201);
    }

    public function destroyAcknowledgement(SalesQuoteTemplate $template, SalesQuoteTemplateAcknowledgement $acknowledgement)
    {
        abort_unless((int) $acknowledgement->template_id === (int) $template->id, 404);

        $acknowledgement->delete();

        return response()->noContent();
    }
}
