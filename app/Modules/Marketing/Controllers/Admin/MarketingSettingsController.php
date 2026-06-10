<?php

namespace App\Modules\Marketing\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Marketing\Support\MarketingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MarketingSettingsController extends Controller
{
    public function edit(MarketingSettings $settings): View
    {
        return view('marketing::Admin.Settings.edit', [
            'settings' => $settings->get(),
            'consentModeOptions' => MarketingSettings::CONSENT_MODE_OPTIONS,
            'unsubscribeModeOptions' => MarketingSettings::UNSUBSCRIBE_MODE_OPTIONS,
            'marketingAccount' => EmailAccount::query()
                ->where('is_active', true)
                ->get()
                ->first(fn (EmailAccount $account): bool => in_array('marketing', (array) $account->defaults_for, true)),
        ]);
    }

    public function update(Request $request, MarketingSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'consent_mode' => ['required', 'string', Rule::in(array_keys(MarketingSettings::CONSENT_MODE_OPTIONS))],
            'unsubscribe_mode' => ['required', 'string', Rule::in(array_keys(MarketingSettings::UNSUBSCRIBE_MODE_OPTIONS))],
            'active_contract_clients_eligible' => ['nullable', 'boolean'],
            'open_tracking_enabled' => ['nullable', 'boolean'],
            'click_tracking_enabled' => ['nullable', 'boolean'],
            'default_batch_size' => ['required', 'integer', 'min:1', 'max:1000'],
            'default_send_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
            'unsubscribe_footer' => ['required', 'string', 'max:2000'],
        ]);

        foreach (['active_contract_clients_eligible', 'open_tracking_enabled', 'click_tracking_enabled'] as $key) {
            $validated[$key] = $request->boolean($key);
        }

        $settings->update($validated);

        return redirect()
            ->route('tech.admin.settings.marketing')
            ->with('success', 'Marketing settings were updated.');
    }
}
