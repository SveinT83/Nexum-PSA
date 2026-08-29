<?php

namespace App\Modules\Integration\Controllers\Admin;

use App\Modules\Integration\Actions\DeleteRmmAlertRule;
use App\Modules\Integration\Actions\SaveRmmAlertRule;
use App\Modules\Integration\Models\RmmAlertRule;
use App\Modules\Integration\Queries\RmmAlertRuleAdminQuery;
use App\Modules\Integration\Requests\Admin\SaveRmmAlertRuleRequest;
use App\Modules\Integration\Support\RmmAlertRuleDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

final class RmmAlertRuleController extends Controller
{
    public function index(
        RmmAlertRuleAdminQuery $rules,
        RmmAlertRuleDefinition $definitions,
    ): View {
        return view('integration::Tech.Admin.System.Integrations.RmmAlertRules.index', [
            ...$rules->indexData(),
            'definitions' => $definitions,
        ]);
    }

    public function create(RmmAlertRuleAdminQuery $rules): View
    {
        return view(
            'integration::Tech.Admin.System.Integrations.RmmAlertRules.form',
            $rules->formData(),
        );
    }

    public function store(
        SaveRmmAlertRuleRequest $request,
        SaveRmmAlertRule $rules,
    ): RedirectResponse {
        $rules->handle($request->validated(), null, $request->user());

        return redirect()
            ->route('tech.admin.system.integrations.rmm-alert-rules.index')
            ->with('status', 'RMM Alert Rule created. It applies only to future new or reopened alerts.');
    }

    public function edit(RmmAlertRule $rule, RmmAlertRuleAdminQuery $rules): View
    {
        return view(
            'integration::Tech.Admin.System.Integrations.RmmAlertRules.form',
            $rules->formData($rule),
        );
    }

    public function update(
        SaveRmmAlertRuleRequest $request,
        RmmAlertRule $rule,
        SaveRmmAlertRule $rules,
    ): RedirectResponse {
        $saved = $rules->handle($request->validated(), $rule, $request->user());

        return redirect()
            ->route('tech.admin.system.integrations.rmm-alert-rules.index')
            ->with('status', 'RMM Alert Rule updated as revision '.$saved->revision.'.');
    }

    public function destroy(
        RmmAlertRule $rule,
        DeleteRmmAlertRule $rules,
    ): RedirectResponse {
        $rules->handle($rule);

        return redirect()
            ->route('tech.admin.system.integrations.rmm-alert-rules.index')
            ->with('status', 'RMM Alert Rule deleted. Existing execution evidence was preserved.');
    }
}
