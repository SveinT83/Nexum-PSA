<?php

namespace App\Modules\Marketing\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Support\MarketingSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarketingSettingsController extends Controller
{
    public function show(MarketingSettings $settings)
    {
        return $this->settingsResponse($settings->get());
    }

    public function update(Request $request, MarketingSettings $settings)
    {
        $validated = $request->validate([
            'consent_mode' => ['sometimes', 'string', Rule::in(array_keys(MarketingSettings::CONSENT_MODE_OPTIONS))],
            'unsubscribe_mode' => ['sometimes', 'string', Rule::in(array_keys(MarketingSettings::UNSUBSCRIBE_MODE_OPTIONS))],
            'active_contract_clients_eligible' => ['sometimes', 'boolean'],
            'open_tracking_enabled' => ['sometimes', 'boolean'],
            'click_tracking_enabled' => ['sometimes', 'boolean'],
            'default_batch_size' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'default_send_interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'quiet_hours_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['sometimes', 'nullable', 'date_format:H:i'],
            'unsubscribe_footer' => ['sometimes', 'string', 'max:2000'],
        ]);

        foreach (['active_contract_clients_eligible', 'open_tracking_enabled', 'click_tracking_enabled'] as $key) {
            if ($request->has($key)) {
                $validated[$key] = $request->boolean($key);
            }
        }

        return $this->settingsResponse($settings->update(array_merge($settings->get(), $validated)));
    }

    private function settingsResponse(array $settings)
    {
        return response()->json([
            'data' => $settings,
            'meta' => [
                'consent_mode_options' => MarketingSettings::CONSENT_MODE_OPTIONS,
                'unsubscribe_mode_options' => MarketingSettings::UNSUBSCRIBE_MODE_OPTIONS,
            ],
        ]);
    }
}
