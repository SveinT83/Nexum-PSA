<?php

namespace App\Modules\Sales\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\EnsureSalesDefaults;
use App\Modules\Sales\Actions\EvaluateSalesQuoteApproval;
use App\Modules\Sales\Models\SalesQuoteAcceptanceSnapshot;
use App\Modules\Sales\Models\SalesQuoteConversionPlan;
use App\Modules\Sales\Models\SalesQuoteTemplate;
use App\Modules\Sales\Models\SalesQuoteTemplateAcknowledgement;
use App\Modules\Sales\Models\SalesQuoteTemplateLine;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Models\SalesSetting;
use App\Modules\Sales\Services\SalesQuoteTemplateWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesSettingsController extends Controller
{
    public function rules(EnsureSalesDefaults $defaults): View
    {
        $defaults->handle();

        return view('sales::Admin.Settings.rules.index', [
            'policy' => SalesSetting::get('cpq_approval_policy', EvaluateSalesQuoteApproval::DEFAULT_POLICY),
        ]);
    }

    public function updateRules(Request $request, EnsureSalesDefaults $defaults): RedirectResponse
    {
        $defaults->handle();

        $data = $request->validate([
            'enabled' => 'nullable|boolean',
            'discount_percent_threshold' => 'required|numeric|min:0|max:100',
            'minimum_margin_percent' => 'required|numeric|min:-100|max:100',
            'quote_total_ex_vat_threshold' => 'required|numeric|min:0',
            'manual_line_ex_vat_threshold' => 'required|numeric|min:0',
        ]);

        SalesSetting::query()->updateOrCreate(
            ['key' => 'cpq_approval_policy'],
            ['value' => [
                'enabled' => (bool) ($data['enabled'] ?? false),
                'discount_percent_threshold' => (float) $data['discount_percent_threshold'],
                'minimum_margin_percent' => (float) $data['minimum_margin_percent'],
                'quote_total_ex_vat_threshold' => (float) $data['quote_total_ex_vat_threshold'],
                'manual_line_ex_vat_threshold' => (float) $data['manual_line_ex_vat_threshold'],
            ]],
        );

        return back()->with('success', 'Sales CPQ approval policy updated.');
    }

    public function workflows(EnsureSalesDefaults $defaults): View
    {
        $defaults->handle();

        return view('sales::Admin.Settings.workflows.index', [
            'templates' => SalesQuoteTemplate::query()
                ->withCount(['optionGroups', 'lines', 'acknowledgements'])
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'reporting' => $this->quoteReporting(),
        ]);
    }

    public function createTemplate(EnsureSalesDefaults $defaults, SalesQuoteTemplateWorkflowService $workflow): View
    {
        $defaults->handle();

        return view('sales::Admin.Settings.workflows.form', array_merge(
            $this->templateViewData(new SalesQuoteTemplate([
                'is_active' => true,
                'customer_segment' => 'general',
            ]), $workflow),
            ['mode' => 'create'],
        ));
    }

    public function storeTemplate(Request $request, SalesQuoteTemplateWorkflowService $workflow): RedirectResponse
    {
        $template = $workflow->createTemplate($request->validate($workflow->templateRules()), $request->user());

        return redirect()
            ->route('tech.admin.settings.sales.quote-templates.edit', $template)
            ->with('success', 'Quote template created.');
    }

    public function editTemplate(EnsureSalesDefaults $defaults, SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow): View
    {
        $defaults->handle();

        return view('sales::Admin.Settings.workflows.form', array_merge(
            $this->templateViewData($template, $workflow),
            ['mode' => 'edit'],
        ));
    }

    public function updateTemplate(Request $request, SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow): RedirectResponse
    {
        $workflow->updateTemplate($template, $request->validate($workflow->templateRules()), $request->user());

        return redirect()
            ->route('tech.admin.settings.sales.quote-templates.edit', $template)
            ->with('success', 'Quote template updated.');
    }

    public function destroyTemplate(SalesQuoteTemplate $template): RedirectResponse
    {
        $template->delete();

        return redirect()
            ->route('tech.admin.settings.sales.quote-templates.index')
            ->with('success', 'Quote template deleted.');
    }

    public function storeTemplateLine(Request $request, SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow): RedirectResponse
    {
        $workflow->createLine($template, $request->validate($workflow->lineRules($template)));

        return redirect()
            ->route('tech.admin.settings.sales.quote-templates.edit', $template)
            ->with('success', 'Template line added.');
    }

    public function destroyTemplateLine(SalesQuoteTemplate $template, SalesQuoteTemplateLine $line): RedirectResponse
    {
        abort_unless((int) $line->template_id === (int) $template->id, 404);

        $line->delete();

        return back()->with('success', 'Template line deleted.');
    }

    public function storeTemplateAcknowledgement(Request $request, SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow): RedirectResponse
    {
        $workflow->createAcknowledgement($template, $request->validate($workflow->acknowledgementRules($template)));

        return redirect()
            ->route('tech.admin.settings.sales.quote-templates.edit', $template)
            ->with('success', 'Template acknowledgement added.');
    }

    public function destroyTemplateAcknowledgement(SalesQuoteTemplate $template, SalesQuoteTemplateAcknowledgement $acknowledgement): RedirectResponse
    {
        abort_unless((int) $acknowledgement->template_id === (int) $template->id, 404);

        $acknowledgement->delete();

        return back()->with('success', 'Template acknowledgement deleted.');
    }

    private function templateViewData(SalesQuoteTemplate $template, SalesQuoteTemplateWorkflowService $workflow): array
    {
        $template->loadMissing(['optionGroups.lines', 'lines.optionGroup', 'acknowledgements.line']);
        $catalog = $workflow->catalog();

        return [
            'template' => $template,
            'targetTypes' => $catalog['target_types'],
            'customerSegments' => $catalog['customer_segments'],
            'sourceTypes' => $catalog['source_types'],
            'lineCatalogs' => $catalog['line_catalogs'],
            'sections' => $catalog['sections'],
            'downstreamTypes' => $catalog['downstream_types'],
            'quoteCadences' => $catalog['billing_cadences'],
            'optionGroupTypes' => $catalog['option_group_types'],
        ];
    }

    private function quoteReporting(): array
    {
        return [
            'templates' => SalesQuoteTemplate::query()->count(),
            'active_templates' => SalesQuoteTemplate::query()->where('is_active', true)->count(),
            'accepted_snapshots' => SalesQuoteAcceptanceSnapshot::query()->count(),
            'pending_conversion_plans' => SalesQuoteConversionPlan::query()->where('status', 'pending')->count(),
            'declined_quotes' => SalesQuoteVersion::query()->where('status', 'declined')->count(),
            'expired_sent_quotes' => SalesQuoteVersion::query()
                ->where('status', 'sent')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now()->toDateString())
                ->count(),
        ];
    }
}
