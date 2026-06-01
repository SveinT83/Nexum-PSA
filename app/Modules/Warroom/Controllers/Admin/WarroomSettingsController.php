<?php

namespace App\Modules\Warroom\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Warroom\Support\WarroomSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarroomSettingsController extends Controller
{
    public function edit(WarroomSettings $settings)
    {
        return view('warroom::Admin.Settings.edit', [
            'settings' => $settings->get(),
            'sectionOptions' => WarroomSettings::SECTION_OPTIONS,
        ]);
    }

    public function update(Request $request, WarroomSettings $settings)
    {
        $validated = $request->validate([
            'due_soon_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'inbox_recent_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'latest_tickets_limit' => ['required', 'integer', 'min:1', 'max:20'],
            'latest_alerts_limit' => ['required', 'integer', 'min:1', 'max:20'],
            'calendar_today_limit' => ['required', 'integer', 'min:1', 'max:20'],
            'recent_integrations_limit' => ['required', 'integer', 'min:1', 'max:20'],
            'enabled_sections' => ['required', 'array', 'min:1'],
            'enabled_sections.*' => ['string', Rule::in(array_keys(WarroomSettings::SECTION_OPTIONS))],
        ]);

        $settings->update($validated);

        return redirect()
            ->route('tech.admin.settings.warroom')
            ->with('success', 'Warroom settings were updated.');
    }
}
