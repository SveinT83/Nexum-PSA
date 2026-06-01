<?php

namespace App\Modules\Risk\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Risk\Support\RiskSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RiskSettingsController extends Controller
{
    public function edit(RiskSettings $settings)
    {
        return view('risk::Admin.Settings.edit', [
            'settings' => $settings->get(),
            'assessmentScopeOptions' => RiskSettings::ASSESSMENT_SCOPE_OPTIONS,
            'assessmentStatusOptions' => RiskSettings::ASSESSMENT_STATUS_OPTIONS,
            'itemStatusOptions' => RiskSettings::ITEM_STATUS_OPTIONS,
        ]);
    }

    public function update(Request $request, RiskSettings $settings)
    {
        $validated = $request->validate([
            'default_assessment_scope' => ['required', 'string', Rule::in(array_keys(RiskSettings::ASSESSMENT_SCOPE_OPTIONS))],
            'default_assessment_status' => ['required', 'string', Rule::in(array_keys(RiskSettings::ASSESSMENT_STATUS_OPTIONS))],
            'default_item_likelihood' => ['required', 'integer', 'min:1', 'max:5'],
            'default_item_impact' => ['required', 'integer', 'min:1', 'max:5'],
            'default_item_status' => ['required', 'string', Rule::in(array_keys(RiskSettings::ITEM_STATUS_OPTIONS))],
            'default_item_review_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $settings->update($validated);

        return redirect()
            ->route('tech.admin.settings.risk')
            ->with('success', 'Risk settings were updated.');
    }
}
