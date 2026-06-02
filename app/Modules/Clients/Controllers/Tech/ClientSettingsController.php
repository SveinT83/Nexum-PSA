<?php

namespace App\Modules\Clients\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Modules\CustomField\Actions\SyncCustomFieldValues;
use App\Modules\CustomField\Support\CustomFieldPresenter;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Http\Request;

class ClientSettingsController extends Controller
{
    public function edit(Client $client, NAbleRmmClient $rmmClient, Request $request, CustomFieldPresenter $customFields)
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

        return view('clients::Tech.Settings.edit', [
            'client' => $client,
            'rmmIntegration' => $rmmIntegration,
            'rmmClients' => $rmmClients,
            'rmmError' => $rmmError,
            'currentRmmId' => $currentRmmId,
            'customFields' => $customFields->editableFor($client, $request->user()),
        ]);
    }

    public function update(Request $request, Client $client, SyncCustomFieldValues $customFields)
    {
        $request->validate([
            'rmm_external_id' => 'nullable|string',
            'custom_fields' => ['nullable', 'array'],
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

        $customFields->handle($client, (array) $request->input('custom_fields', []), $request->user(), 'ui');

        return redirect()->route('tech.clients.show', $client->id)
            ->with('success', 'Client settings updated.');
    }
}
