<?php

namespace App\Modules\Clients\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\CustomField\Actions\SyncCustomFieldValues;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClientCustomFieldValueController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Client custom field values
    |--------------------------------------------------------------------------
    |
    | Field definitions are owned by the CustomField module, but this controller
    | owns the client-specific value update surface used from the client profile.
    |
    */
    public function update(
        Request $request,
        Client $client,
        CustomFieldDefinition $definition,
        SyncCustomFieldValues $syncCustomFieldValues,
    ): RedirectResponse {
        abort_unless($definition->model_type === $client->getMorphClass(), 404);

        $editableDefinitions = $syncCustomFieldValues->definitionsFor($client, $request->user(), 'ui');
        abort_unless($editableDefinitions->contains('id', $definition->id), 403);

        $validated = $request->validate([
            'value' => ['nullable'],
            'value.*' => ['nullable'],
        ]);

        $syncCustomFieldValues->handle(
            $client,
            [$definition->key => $validated['value'] ?? null],
            $request->user(),
            'ui',
        );

        return redirect()
            ->route('tech.clients.show', ['client' => $client, 'tab' => 'custom-fields'])
            ->with('success', "{$definition->label} updated.");
    }

    public function updateSite(
        Request $request,
        ClientSite $site,
        CustomFieldDefinition $definition,
        SyncCustomFieldValues $syncCustomFieldValues,
    ): RedirectResponse {
        abort_unless($definition->model_type === $site->getMorphClass(), 404);

        $editableDefinitions = $syncCustomFieldValues->definitionsFor($site, $request->user(), 'ui');
        abort_unless($editableDefinitions->contains('id', $definition->id), 403);

        $validated = $request->validate([
            'value' => ['nullable'],
            'value.*' => ['nullable'],
        ]);

        $syncCustomFieldValues->handle(
            $site,
            [$definition->key => $validated['value'] ?? null],
            $request->user(),
            'ui',
        );

        return redirect()
            ->route('tech.clients.sites.show', ['site' => $site, 'tab' => 'custom-fields'])
            ->with('success', "{$definition->label} updated.");
    }
}
