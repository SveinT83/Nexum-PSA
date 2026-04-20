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

        return view('tech.clients.settings.edit', compact('client', 'rmmIntegration', 'rmmClients', 'rmmError'));
    }

    public function update(Request $request, Client $client)
    {
        $request->validate([
            'rmm_id' => 'nullable|string',
        ]);

        $client->rmm_id = $request->rmm_id;
        $client->save();

        return redirect()->route('tech.clients.show', $client->id)
            ->with('success', 'Client settings updated.');
    }
}
