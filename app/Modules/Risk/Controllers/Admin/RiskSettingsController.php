<?php

namespace App\Modules\Risk\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Risk\Support\RiskSettings;
use Illuminate\Http\Request;

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
        $input = $request->except(['_token', '_method']);
        app(\App\Modules\Risk\Actions\UpdateRiskSettings::class)->handle($input);

        return redirect()
            ->route('tech.admin.settings.risk')
            ->with('success', 'Risk settings were updated.');
    }
}
