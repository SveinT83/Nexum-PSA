<?php

namespace App\Modules\Asset\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\Integration;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Asset\Actions\ExecuteAssetCommand;
use App\Modules\Asset\Actions\ImportNableAssets;
use App\Modules\Asset\Actions\ImportTacticalAssets;
use App\Modules\Asset\Actions\SyncAssetAlerts;
use App\Modules\Asset\Support\AssetInput;
use Illuminate\Http\Request;

/** Explicit scope previews and guarded provider commands; no generic integration dispatcher. */
class AssetProviderController extends Controller
{
    private function plan(Request $request, bool $rmm): array
    {
        AssetInput::allowed($request, ['client_id', 'site_id', 'asset_id', 'provider', 'preview_revision', 'confirm', 'inspect_provider']);
        $request->validate(['client_id' => 'sometimes|nullable|integer|exists:clients,id',
            'site_id' => 'sometimes|nullable|integer|exists:client_sites,id', 'asset_id' => 'sometimes|nullable|integer|exists:assets,id',
            'provider' => [$rmm ? 'required' : 'prohibited', 'in:rmm,tactical_rmm'],
            'confirm' => 'sometimes|boolean', 'preview_revision' => 'sometimes|string|max:200',
            'inspect_provider' => $rmm ? 'sometimes|boolean' : 'prohibited']);
        $clientId = $request->integer('client_id') ?: null;
        $siteId = $request->integer('site_id') ?: null;
        $assetId = $request->integer('asset_id') ?: null;
        abort_if(! $rmm && $request->has('site_id'), 422, 'Alert sync is scoped by Client or Asset.');
        if ($siteId) {
            $site = ClientSite::findOrFail($siteId);
            abort_if(! $clientId || (int) $site->client_id !== $clientId, 422, 'Site must belong to the selected Client.');
        }
        if ($assetId) {
            $asset = Asset::findOrFail($assetId);
            abort_if($clientId && (int) $asset->client_id !== $clientId, 422, 'Asset does not belong to the selected Client.');
            if ($rmm) {
                abort_unless($asset->client_id, 422, 'Internal Assets cannot use client RMM import.');
                $clientId = $asset->client_id;
                $siteId = $asset->site_id;
            }
        }
        $integrations = Integration::whereIn('type', $rmm ? [$request->input('provider')] : ['rmm', 'tactical_rmm'])
            ->where('status', 'active')->orderBy('id')->get();
        $query = Asset::query()->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->when($assetId && ! $rmm, fn ($q) => $q->whereKey($assetId));
        $scope = ['client_id' => $clientId, 'site_id' => $siteId, 'asset_id' => $assetId,
            'provider' => $rmm ? $request->input('provider') : null];
        // Include effective mappings, active clients and ownership in the fencing value.
        $snapshot = [$scope, $query->orderBy('id')->get(['id', 'client_id', 'site_id', 'updated_at'])->toArray(),
            $integrations->map(fn ($i) => [$i->id, $i->type, (string) $i->updated_at])->all(),
            \App\Models\System\Integrations\ClientRmmLink::whereIn('integration_id', $integrations->pluck('id'))->orderBy('id')
                ->get(['id', 'integration_id', 'linkable_type', 'linkable_id', 'external_id'])->toArray(),
            Client::when($clientId, fn ($q) => $q->whereKey($clientId))->orderBy('id')->get(['id', 'active'])->toArray()];

        return ['scope' => $scope, 'assets_considered' => $query->count(),
            'providers' => $integrations->map(fn ($i) => ['id' => $i->id, 'type' => $i->type])->all(),
            'preview_revision' => hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), (string) config('app.key')),
            'execution_enabled' => (bool) config('assets-api.provider_execution_enabled')];
    }

    public function previewAlerts(Request $request)
    {
        return ['data' => $this->plan($request, false)];
    }

    public function previewRmm(Request $request)
    {
        $plan = $this->plan($request, true);
        if ($request->boolean('inspect_provider')) {
            $plan['inspection'] = $this->import($plan['scope'], true);
        }

        return ['data' => $plan];
    }

    private function execute(Request $request, bool $rmm, ExecuteAssetCommand $commands)
    {
        abort_unless(config('assets-api.provider_execution_enabled'), 409, 'Asset API provider execution is disabled.');
        abort_unless($request->boolean('confirm') && $request->filled('preview_revision'), 428, 'Preview and explicit confirmation are required.');

        abort_if($request->has('inspect_provider'), 422, 'Provider inspection is a preview-only option.');

        // The replay identity is checked before fencing. A successful import can itself change the preview.
        return $commands->handle($request, $rmm ? 'rmm.sync' : 'alerts.sync', function () use ($request, $rmm) {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $rmm) {
                \Illuminate\Support\Facades\DB::table('asset_api_mutex')->where('id', 1)->lockForUpdate()->first();
                $plan = $this->plan($request, $rmm);
                abort_unless(hash_equals($plan['preview_revision'], $request->string('preview_revision')->toString()), 409, 'Provider scope changed; obtain a new preview.');
                $scope = $plan['scope'];
                if ($rmm) {
                    return $this->import($scope);
                }

                return app(SyncAssetAlerts::class)->handle($scope['client_id'], $scope['asset_id']) + ['scope' => $scope];
            });
        }, provider: true);
    }

    public function syncAlerts(Request $request, ExecuteAssetCommand $commands)
    {
        return $this->execute($request, false, $commands);
    }

    public function syncRmm(Request $request, ExecuteAssetCommand $commands)
    {
        return $this->execute($request, true, $commands);
    }

    private function import(array $scope, bool $preview = false): array
    {
        $integration = Integration::where('type', $scope['provider'])->where('status', 'active')->first();
        abort_unless($integration, 409, 'The selected provider is not active.');
        if ($scope['provider'] === 'tactical_rmm') {
            return app(ImportTacticalAssets::class)->handle($integration, $scope['client_id'], $scope['site_id'], $preview) + ['scope' => $scope];
        }
        $result = ['created' => 0, 'updated' => 0, 'errors' => 0, 'candidates' => 0];
        $clients = Client::where('active', true)->whereHas('rmmLinks', fn ($q) => $q->where('integration_id', $integration->id))
            ->when($scope['client_id'], fn ($q) => $q->whereKey($scope['client_id']))->orderBy('id')->get();
        foreach ($clients as $client) {
            $current = app(ImportNableAssets::class)->handle($integration, $client, $scope['site_id'], $preview);
            foreach (array_keys($result) as $key) {
                $result[$key] += $current[$key];
            }
        }

        return ['state' => ! $clients->count() ? 'skipped' : ($result['errors'] ? 'partial' : 'completed'), 'scope' => $scope] + $result;
    }
}
