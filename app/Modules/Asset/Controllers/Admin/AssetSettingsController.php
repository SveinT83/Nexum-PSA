<?php

namespace App\Modules\Asset\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Support\AssetSettings;
use Illuminate\Http\Request;

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
        app(\App\Modules\Asset\Actions\UpdateAssetSettings::class)->handle($request);

        return redirect()
            ->route('tech.admin.settings.assets')
            ->with('success', 'Asset settings were updated.');
    }
}
