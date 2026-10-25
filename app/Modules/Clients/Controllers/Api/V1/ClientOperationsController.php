<?php

namespace App\Modules\Clients\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Clients\ClientResource;
use App\Models\Clients\Client;
use App\Models\Clients\ClientFormat;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\Integration;
use App\Modules\Clients\Actions\ExecuteClientCommand;
use App\Modules\Clients\Models\ClientApiCommand;
use App\Modules\Clients\Support\ClientInput;
use App\Modules\Clients\Support\ClientRevision;
use App\Modules\CustomField\Support\CustomFieldPresenter;
use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Integration\Support\DomainApiRead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Shared settings, administrative actions and exact operation read-back. */
class ClientOperationsController extends Controller
{
    private function command(Request $request, callable $apply, bool $required = true): array
    {
        return app(ExecuteClientCommand::class)->handle($request, fn () => null, $apply, $required);
    }

    public function settings(Request $request, Client $client)
    {
        $rmm = Integration::query()->where('type', 'rmm')->first();

        return response()->json(['data' => [
            'client' => (new ClientResource($client->load('sites')))->resolve($request),
            'rmm' => ['configured' => $rmm?->status === 'active', 'integration_id' => $rmm?->id,
                'external_id' => $rmm ? $client->rmmLinks()->where('integration_id', $rmm->id)->value('external_id') : null,
                'api_provider_writes_enabled' => (bool) config('clients-api.provider_writes_enabled')],
            'custom_fields' => app(CustomFieldPresenter::class)->editableFor($client, $request->user())->map(fn ($f) => array_diff_key($f, ['definition' => true]))->values(),
            'revision' => ClientRevision::of($client),
        ]]);
    }

    public function updateSettings(Request $request, Client $client)
    {
        $data = DomainApiInput::validate($request, [
            'name' => 'sometimes|required|string|max:255', 'client_number' => ['sometimes', 'nullable', 'string', Rule::unique('clients', 'client_number')->ignore($client->id)],
            'org_no' => 'sometimes|nullable|string|max:50', 'client_format_id' => 'sometimes|nullable|exists:client_formats,id',
            'website' => 'sometimes|nullable|string|max:255', 'billing_email' => 'sometimes|nullable|email|max:255',
            'notes' => 'sometimes|nullable|string', 'active' => 'sometimes|boolean', 'rmm_external_id' => 'sometimes|nullable|string|max:255',
            'custom_fields' => 'sometimes|array',
        ]);
        if (isset($data['client_number']) && $data['client_number'] !== $client->client_number) {
            $request->validate(['client_number' => 'regex:/^\d{5}$/']);
        }
        ClientInput::fields($request, $client);
        $result = $this->command($request, function () use ($request, $client, $data) {
            $fresh = Client::query()->lockForUpdate()->findOrFail($client->id);
            ClientRevision::check($request, $fresh);
            if ($request->has('rmm_external_id')) {
                abort_unless(Integration::query()->where('type', 'rmm')->where('status', 'active')->exists(), 409, 'RMM is not active; mapping was not changed.');
            }
            app(\App\Modules\Clients\Actions\UpdateClientSettings::class)->handle($request, $fresh, $data, true);

            return ['client_id' => $client->id];
        });

        return response()->json($result + ['data' => (new ClientResource($client->fresh()->load('sites')))->resolve($request)]);
    }

    public function notes(Request $request, Client $client)
    {
        $data = DomainApiInput::validate($request, ['notes' => 'present|nullable|string']);
        $result = $this->command($request, function () use ($request, $client, $data) {
            $fresh = Client::query()->lockForUpdate()->findOrFail($client->id);
            ClientRevision::check($request, $fresh);
            app(\App\Modules\Clients\Actions\UpdateClientNotes::class)->handle($fresh, $data['notes']);

            return ['client_id' => $client->id];
        });

        return response()->json($result + ['data' => (new ClientResource($client->fresh()->load('sites')))->resolve($request)]);
    }

    public function siteContext(Request $request, ClientSite $site)
    {
        return response()->json(['data' => [
            'site' => (new \App\Modules\Clients\Resources\Api\V1\ClientSiteResource($site->load('client')))->resolve($request),
            'custom_fields' => app(CustomFieldPresenter::class)->editableFor($site, $request->user())->map(fn ($f) => array_diff_key($f, ['definition' => true]))->values(),
            'default_site_id' => $site->client->sites()->where('is_default', true)->value('id'),
        ]]);
    }

    public function deleteSite(Request $request)
    {
        DomainApiInput::validate($request, []);
        $id = (int) $request->route('site');
        $result = $this->command($request, function () use ($request, $id) {
            $site = ClientSite::query()->lockForUpdate()->findOrFail($id);
            ClientRevision::check($request, $site);
            app(\App\Modules\Clients\Actions\DeleteClientSite::class)->handle($site);

            return ['data' => ['id' => $id, 'deleted' => true]];
        });

        return response()->json($result);
    }

    public function formats(Request $request)
    {
        return response()->json(DomainApiRead::paginateCollection(ClientFormat::query()->withCount('clients')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($f) => $this->format($f)), $request));
    }

    private function format(ClientFormat $row): array
    {
        return $row->only(['id', 'name', 'code', 'description', 'is_active', 'sort_order', 'clients_count']) + ['revision' => ClientRevision::of($row)];
    }

    public function formatShow(Request $request, ClientFormat $clientFormat)
    {
        return response()->json(['data' => $this->format($clientFormat->loadCount('clients'))]);
    }

    public function formatStore(Request $request)
    {
        ClientInput::allowed($request, ['name', 'code', 'description', 'sort_order', 'is_active']);
        $result = $this->command($request, function () use ($request) {
            $data = app(\App\Modules\Clients\Actions\ClientFormatWorkflow::class)->data($request);
            $row = ClientFormat::query()->create($data);

            return ['format_id' => $row->id];
        });

        return response()->json($result + ['data' => $this->format(ClientFormat::query()->withCount('clients')->findOrFail($result['format_id']))], 201);
    }

    public function formatUpdate(Request $request, ClientFormat $clientFormat)
    {
        ClientInput::allowed($request, ['name', 'code', 'description', 'sort_order', 'is_active']);
        $result = $this->command($request, function () use ($request, $clientFormat) {
            $fresh = ClientFormat::query()->lockForUpdate()->findOrFail($clientFormat->id);
            ClientRevision::check($request, $fresh);
            $request->merge(array_diff_key($fresh->only(['name', 'code', 'description', 'sort_order', 'is_active']), $request->all()));
            $fresh->update(app(\App\Modules\Clients\Actions\ClientFormatWorkflow::class)->data($request, $fresh));

            return ['format_id' => $fresh->id];
        });

        return response()->json($result + ['data' => $this->format($clientFormat->fresh()->loadCount('clients'))]);
    }

    public function operation(Request $request)
    {
        $receipt = ClientApiCommand::query()->where('actor_key', ExecuteClientCommand::actorKey($request))->findOrFail($request->route('command'));

        return response()->json(['data' => ['id' => $receipt->id, 'operation' => $receipt->operation, 'state' => $receipt->state,
            'reconciliation_required' => in_array($receipt->state, ['running', 'unknown'], true), 'created_at' => $receipt->created_at, 'updated_at' => $receipt->updated_at]]);
    }
}
