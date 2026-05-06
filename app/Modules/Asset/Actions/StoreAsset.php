<?php

namespace App\Modules\Asset\Actions;

use App\Models\Tech\Work\Assets\Asset;
use Illuminate\Http\Request;

/**
 * Validates and creates manually registered assets.
 *
 * RMM imports create/update assets through integration jobs, while this action
 * owns the plain HTTP fallback used by the Tech UI. Livewire keeps its own form
 * validation for now, but the rule set should stay aligned with this action.
 */
class StoreAsset
{
    public function handle(Request $request): Asset
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
        ]);

        return Asset::create($validated);
    }
}
