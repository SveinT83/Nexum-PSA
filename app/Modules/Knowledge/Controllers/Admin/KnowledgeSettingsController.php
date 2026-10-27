<?php

namespace App\Modules\Knowledge\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Knowledge\Support\KnowledgeSettings;
use Illuminate\Http\Request;

class KnowledgeSettingsController extends Controller
{
    public function edit(KnowledgeSettings $settings)
    {
        return view('knowledge::Admin.Settings.edit', [
            'settings' => $settings->get(),
            'visibilityOptions' => KnowledgeSettings::VISIBILITY_OPTIONS,
            'statusOptions' => KnowledgeSettings::STATUS_OPTIONS,
        ]);
    }

    public function update(Request $request, KnowledgeSettings $settings)
    {
        $validated = $request->validate(KnowledgeSettings::rules());

        $settings->update($validated);

        return redirect()
            ->route('tech.admin.settings.knowledge')
            ->with('success', 'Knowledge settings were updated.');
    }
}
