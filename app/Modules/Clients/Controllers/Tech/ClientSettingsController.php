<?php

namespace App\Modules\Clients\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientFormat;
use App\Models\System\Integrations\Integration;
use App\Modules\CustomField\Actions\SyncCustomFieldValues;
use App\Modules\CustomField\Support\CustomFieldPresenter;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'clientFormats' => ClientFormat::activeOptions(),
            'customFields' => $customFields->editableFor($client, $request->user()),
        ]);
    }

    public function update(Request $request, Client $client, SyncCustomFieldValues $customFields)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'client_number' => [
                'nullable',
                'string',
                'regex:/^\d{5}$/',
                Rule::unique('clients', 'client_number')->ignore($client->id),
            ],
            'org_no' => ['nullable', 'string', 'max:50'],
            'client_format_id' => ['nullable', Rule::exists('client_formats', 'id')],
            'website' => ['nullable', 'string', 'max:255'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
            'rmm_external_id' => ['nullable', 'string'],
            'custom_fields' => ['nullable', 'array'],
        ]);

        $client->forceFill([
            'name' => $validated['name'],
            'client_number' => $validated['client_number'] ?? null,
            'org_no' => $validated['org_no'] ?? null,
            'client_format_id' => $validated['client_format_id'] ?? null,
            'website' => $validated['website'] ?? null,
            'billing_email' => $validated['billing_email'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'active' => $request->boolean('active'),
        ])->save();

        $rmmIntegration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        if ($rmmIntegration && $request->has('rmm_external_id')) {
            if ($validated['rmm_external_id'] ?? null) {
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
