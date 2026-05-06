<?php

namespace App\Modules\Asset\Actions;

use App\Models\Tech\Work\Assets\Asset;
use Illuminate\Http\Request;

/**
 * Plain HTTP fallback for updating an asset.
 *
 * The current edit screen saves through Livewire, but keeping a real update
 * action makes the module complete and protects the route if a non-Livewire
 * form is added later.
 */
class UpdateAsset
{
    public function handle(Request $request, Asset $asset): Asset
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'site_id' => 'nullable|exists:client_sites,id',
            'user_id' => 'nullable|exists:client_users,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'name' => 'required|string|max:255',
            'type' => 'required|in:server,pc,laptop,switch,ap,firewall,mobile,other',
            'vendor' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'mac_address' => 'nullable|string|max:255',
            'ip_address' => 'nullable|ip',
            'ip_type' => 'required|in:dhcp,fixed',
            'hostname' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
        ]);

        $asset->update($validated);

        return $asset;
    }
}
