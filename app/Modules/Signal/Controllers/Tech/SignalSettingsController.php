<?php

namespace App\Modules\Signal\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Signal\Actions\EnsureSignalDefaults;
use App\Modules\Signal\Support\SignalSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SignalSettingsController extends Controller
{
    public function edit(EnsureSignalDefaults $defaults, SignalSettings $settings): View
    {
        $defaults->handle();

        return view('signal::Tech.settings', [
            'settings' => $settings->get(),
            'settingsSupport' => $settings,
            'agent' => app(\App\Modules\Signal\Support\SignalSettingsForm::class)->signalAgent(),
        ]);
    }

    public function update(Request $request, SignalSettings $settings): RedirectResponse
    {
        $validated = app(\App\Modules\Signal\Support\SignalSettingsForm::class)->validated($request);

        $settings->update($validated);

        return redirect()
            ->route('tech.admin.system.signals.settings.edit')
            ->with('status', 'Signal settings were updated.');
    }
}
