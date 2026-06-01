<?php

namespace App\Modules\Knowledge\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Knowledge\Support\KnowledgeSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $validated = $request->validate([
            'default_visibility' => ['required', 'string', Rule::in(array_keys(KnowledgeSettings::VISIBILITY_OPTIONS))],
            'default_status' => ['required', 'string', Rule::in(array_keys(KnowledgeSettings::STATUS_OPTIONS))],
            'default_review_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'default_priority' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        $settings->update($validated);

        return redirect()
            ->route('tech.admin.settings.knowledge')
            ->with('success', 'Knowledge settings were updated.');
    }
}
