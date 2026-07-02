<?php

namespace App\Modules\Asset\Actions;

use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Asset\Support\AssetSettings;
use App\Modules\Asset\Support\AssetWorkContextPayload;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Plain HTTP fallback for updating an asset.
 *
 * The current edit screen saves through Livewire, but keeping a real update
 * action makes the module complete and protects the route if a non-Livewire
 * form is added later.
 */
class UpdateAsset
{
    public function __construct(private readonly AssetWorkContextPayload $contextPayload)
    {
    }

    public function handle(Request $request, Asset $asset): Asset
    {
        $settings = app(AssetSettings::class);

        $validated = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'site_id' => 'nullable|exists:client_sites,id',
            'user_id' => 'nullable|exists:client_users,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in($settings->enabledTypeValues($asset->type))],
            'vendor' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'mac_address' => 'nullable|string|max:255',
            'ip_address' => 'nullable|ip',
            'ip_type' => ['required', Rule::in(array_keys(AssetSettings::IP_TYPE_OPTIONS))],
            'hostname' => 'nullable|string|max:255',
            'sensitivity_level' => ['nullable', Rule::in(Asset::SENSITIVITY_LEVELS)],
            'criticality_level' => ['nullable', Rule::in(Asset::CRITICALITY_LEVELS)],
            'status' => ['nullable', Rule::in($settings->statusValues($asset->status))],
        ]);

        $asset->update($this->contextPayload->normalize($validated, $asset));

        return $asset;
    }
}
