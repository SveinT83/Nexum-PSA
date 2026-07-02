<?php

namespace App\Modules\Asset\Actions;

use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Asset\Support\AssetSettings;
use App\Modules\Asset\Support\AssetWorkContextPayload;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Validates and creates manually registered assets.
 *
 * RMM imports create/update assets through integration jobs, while this action
 * owns the plain HTTP fallback used by the Tech UI. Livewire keeps its own form
 * validation for now, but the rule set should stay aligned with this action.
 */
class StoreAsset
{
    public function __construct(private readonly AssetWorkContextPayload $contextPayload)
    {
    }

    public function handle(Request $request): Asset
    {
        $settings = app(AssetSettings::class);
        $request->merge($settings->manualCreateDefaults($request->all()));

        $validated = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'site_id' => 'nullable|exists:client_sites,id',
            'user_id' => 'nullable|exists:client_users,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in($settings->enabledTypeValues($request->string('type')->toString()))],
            'vendor' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'mac_address' => 'nullable|string|max:255',
            'ip_address' => 'nullable|ip',
            'ip_type' => ['required', Rule::in(array_keys(AssetSettings::IP_TYPE_OPTIONS))],
            'hostname' => 'nullable|string|max:255',
            'sensitivity_level' => ['nullable', Rule::in(Asset::SENSITIVITY_LEVELS)],
            'criticality_level' => ['nullable', Rule::in(Asset::CRITICALITY_LEVELS)],
            'status' => ['nullable', Rule::in($settings->statusValues($request->string('status')->toString()))],
        ]);

        return Asset::create($this->contextPayload->normalize($validated, creating: true));
    }
}
