<?php

namespace App\Modules\Asset\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Support\AssetSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetSettingsController extends Controller
{
    public function edit(AssetSettings $settings)
    {
        return view('asset::Admin.Settings.edit', [
            'settings' => $settings->get(),
            'typeOptions' => AssetSettings::TYPE_OPTIONS,
            'ipTypeOptions' => $settings->ipTypeOptions(),
            'statusOptions' => $settings->statusOptions(),
        ]);
    }

    public function update(Request $request, AssetSettings $settings)
    {
        $validated = $request->validate([
            'enabled_types' => ['required', 'array', 'min:1'],
            'enabled_types.*' => ['string', Rule::in(array_keys(AssetSettings::TYPE_OPTIONS))],
            'default_type' => ['required', 'string', Rule::in((array) $request->input('enabled_types', []))],
            'default_ip_type' => ['required', 'string', Rule::in(array_keys(AssetSettings::IP_TYPE_OPTIONS))],
            'default_status' => ['required', 'string', Rule::in(array_keys(AssetSettings::STATUS_OPTIONS))],
        ]);

        $settings->update($validated);

        return redirect()
            ->route('tech.admin.settings.assets')
            ->with('success', 'Asset settings were updated.');
    }
}
