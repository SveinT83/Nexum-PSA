<?php

namespace App\Modules\Asset\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\ClientRmmLink;
use App\Models\System\Integrations\Integration;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;

/** Shared N-able asset import used by the browser and typed Asset commands. */
class ImportNableAssets
{
    public function handle(Integration $integration, Client $localClient, ?int $siteId = null, bool $preview = false): array
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($integration, $localClient, $siteId, $preview) {
            \Illuminate\Support\Facades\DB::table('asset_api_mutex')->where('id', 1)->lockForUpdate()->first();

            return $this->import($integration, $localClient, $siteId, $preview);
        });
    }

    private function import(Integration $integration, Client $localClient, ?int $siteId, bool $preview): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => 0, 'candidates' => 0];
        $client = app(NAbleRmmClient::class, ['integration' => $integration]);

        // If we have a targeted site ID, we need to fetch its RMM ID to filter the RMM results
        $targetRmmSiteId = null;
        if ($siteId) {
            $targetSite = ClientSite::where('id', $siteId)
                ->where('client_id', $localClient->id)
                ->first();

            if ($targetSite) {
                $link = ClientRmmLink::where('integration_id', $integration->id)
                    ->where('linkable_type', ClientSite::class)
                    ->where('linkable_id', $targetSite->id)
                    ->first();

                $targetRmmSiteId = $link ? (string) $link->external_id : null;
            }

            // If the targeted site doesn't have an RMM ID, we can't sync it
            if (! $targetRmmSiteId) {
                $result['errors']++;

                return $result;
            }
        }

        $deviceTypes = ['server', 'workstation'];

        // Get Client RMM ID
        $clientLink = $localClient->rmmLinks()
            ->where('integration_id', $integration->id)
            ->first();

        if (! $clientLink) {
            $result['errors']++;

            return $result;
        }

        foreach ($deviceTypes as $type) {
            $rmmDevices = $client->listDevices($clientLink->external_id, $type);

            if (isset($rmmDevices['error'])) {
                // Ignore errors for mobile devices if not supported by the RMM instance
                if ($type === 'mobile') {
                    continue;
                }

                // If it's empty response but status OK, it's not really an error
                if (str_contains($rmmDevices['error'], 'No devices found') || $rmmDevices['error'] === 'OK') {
                    continue;
                }

                $result['errors']++;

                continue;
            }

            foreach ($rmmDevices as $rmmDevice) {
                // Skip entries that are actually error messages or not devices
                if (isset($rmmDevice['error']) || ! isset($rmmDevice['deviceid']) || empty($rmmDevice['deviceid'])) {
                    continue;
                }

                // Skip if site ID is missing in RMM data
                if (empty($rmmDevice['siteid'])) {
                    continue;
                }

                // If we are targeting a specific site, skip devices that don't match
                if ($targetRmmSiteId && (string) $rmmDevice['siteid'] !== $targetRmmSiteId) {
                    continue;
                }

                // Try to find corresponding local site
                // Check Links first
                $result['candidates']++;
                if ($preview) {
                    continue;
                }
                $localSite = $this->resolveLocalSiteForDevice($integration, $localClient, $rmmDevice);

                if (! $localSite) {
                    continue;
                }

                $asset = \App\Models\Tech\Work\Assets\Asset::whereHas('rmmLinks', function ($query) use ($integration, $rmmDevice) {
                    $query->where('integration_id', $integration->id)
                        ->where('external_id', (string) $rmmDevice['deviceid']);
                })->first();

                if (! $asset && (! empty($rmmDevice['name']) || ! empty($rmmDevice['serial_number']))) {
                    // Fallback to match by name/serial if no link exists
                    $asset = \App\Models\Tech\Work\Assets\Asset::where('client_id', $localClient->id)
                        ->where(function ($query) use ($rmmDevice) {
                            if (! empty($rmmDevice['name'])) {
                                $query->where('hostname', $rmmDevice['name']);
                            }
                            if (! empty($rmmDevice['serial_number'])) {
                                $query->orWhere('serial_number', $rmmDevice['serial_number']);
                            }
                        })->first();
                }

                $assetData = [
                    'client_id' => $localClient->id,
                    'site_id' => $localSite->id,
                    'name' => $rmmDevice['name'],
                    'type' => $rmmDevice['type'],
                    'vendor' => $rmmDevice['vendor'] ?: null,
                    'model' => $rmmDevice['model'] ?: null,
                    'serial_number' => $rmmDevice['serial_number'] ?: null,
                    'mac_address' => $rmmDevice['mac_address'] ?: null,
                    'ip_address' => $rmmDevice['ip_address'] ?: null,
                    'hostname' => $rmmDevice['name'],
                    'source' => 'nable',
                    'is_managed' => true,
                    'status' => 'unknown',
                    'last_seen_at' => now(),
                    'metadata' => [
                        'os' => $rmmDevice['os'],
                        'rmm_type' => $type,
                    ],
                ];

                // Never transfer an asset across an existing provider mapping silently.
                if ($asset && ((int) $asset->client_id !== (int) $localClient->id)) {
                    $result['errors']++;

                    continue;
                }
                $assetData = app(\App\Modules\Asset\Support\AssetWorkContextPayload::class)->normalize($assetData, $asset, ! $asset);
                if ($asset) {
                    $asset->update($assetData);
                    $result['updated']++;
                } else {
                    $asset = \App\Models\Tech\Work\Assets\Asset::create($assetData);
                    $result['created']++;
                }

                // Ensure RMM link exists
                ClientRmmLink::updateOrCreate(
                    [
                        'integration_id' => $integration->id,
                        'linkable_type' => \App\Models\Tech\Work\Assets\Asset::class,
                        'linkable_id' => $asset->id,
                    ],
                    [
                        'external_id' => (string) $rmmDevice['deviceid'],
                    ]
                );
            }
        }

        return $result;
    }

    /**
     * Resolve the local Site for a device. When the Site has not been imported
     * yet, create a placeholder so asset import can still complete.
     */
    protected function resolveLocalSiteForDevice(Integration $integration, Client $localClient, array $rmmDevice): ?ClientSite
    {
        $rmmSiteId = (string) ($rmmDevice['siteid'] ?? '');

        if ($rmmSiteId === '') {
            return null;
        }

        $siteLink = ClientRmmLink::where('integration_id', $integration->id)
            ->where('linkable_type', ClientSite::class)
            ->where('external_id', $rmmSiteId)
            ->first();

        if ($siteLink && $siteLink->linkable instanceof ClientSite) {
            abort_unless((int) $siteLink->linkable->client_id === (int) $localClient->id, 409, 'Provider Site mapping belongs to another Client.');

            return $siteLink->linkable;
        }

        $localSite = ClientSite::firstOrCreate(
            [
                'client_id' => $localClient->id,
                'name' => 'RMM Site '.$rmmSiteId,
            ],
            [
                'is_default' => ! ClientSite::where('client_id', $localClient->id)->exists(),
            ],
        );

        ClientRmmLink::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'external_id' => $rmmSiteId,
                'linkable_type' => ClientSite::class,
            ],
            [
                'linkable_id' => $localSite->id,
                'metadata' => [
                    'source' => 'asset_sync_placeholder',
                ],
            ],
        );

        return $localSite;
    }
}
