<?php

namespace App\Http\Controllers\Tech\Clients;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Http\Request;

class ClientSettingsController extends Controller
{
    public function edit(Client $client, NAbleRmmClient $rmmClient)
    {
        $rmmIntegration = Integration::where('type', 'rmm')->first();
        $rmmClients = [];
        $rmmError = null;

        if ($rmmIntegration && $rmmIntegration->status === 'active') {
            $response = $rmmClient->listClients();
            if (isset($response['error'])) {
                $rmmError = $response['error'];
            } else {
                $rmmClients = $response;
            }
        }

        // Get current RMM Link for this specific integration
        $currentRmmId = null;
        if ($rmmIntegration) {
            $link = $client->rmmLinks()->where('integration_id', $rmmIntegration->id)->first();
            $currentRmmId = $link ? $link->external_id : null;
        }

        return view('tech.clients.settings.edit', compact('client', 'rmmIntegration', 'rmmClients', 'rmmError', 'currentRmmId'));
    }

    public function update(Request $request, Client $client)
    {
        $request->validate([
            'rmm_external_id' => 'nullable|string',
        ]);

        $rmmIntegration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        if ($rmmIntegration) {
            if ($request->rmm_external_id) {
                \App\Models\System\Integrations\ClientRmmLink::updateOrCreate(
                    [
                        'integration_id' => $rmmIntegration->id,
                        'linkable_type' => Client::class,
                        'linkable_id' => $client->id,
                    ],
                    [
                        'external_id' => $request->rmm_external_id,
                    ]
                );
            } else {
                $client->rmmLinks()->where('integration_id', $rmmIntegration->id)->delete();
            }
        }

        return redirect()->route('tech.clients.show', $client->id)
            ->with('success', 'Client settings updated.');
    }
}
