<?php

namespace App\Jobs\Integrations;

use App\Models\System\Integrations\Integration;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Tech\Work\Assets\Asset;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * NAbleRmmSyncJob
 *
 * This background job handles the automated synchronization between tdPSA and N-able RMM.
 * It is scheduled to run hourly and performs tasks based on the enabled integration settings:
 * - Syncing clients from RMM (Import)
 * - Syncing local clients to RMM (Export)
 * - Syncing sites from RMM (Import)
 * - Syncing local sites to RMM (Export)
 */
class NAbleRmmSyncJob implements ShouldQueue
{
    use Queueable;

    /**
     * Executes the synchronization logic.
     * Respects the 'rmm' integration status and specific sync toggles in config.
     */
    public function handle(): void
    {
        $integration = Integration::where('type', 'rmm')->where('status', 'active')->first();

        if (!$integration) {
            return;
        }

        $config = $integration->config ?? [];
        $client = new NAbleRmmClient($integration);

        if (!$client->isConfigured()) {
            Log::warning('N-able RMM Sync Job: Integration not fully configured.');
            return;
        }

        // 1. Sync Clients FROM RMM
        if (!empty($config['client_sync_from'])) {
            $this->syncClientsFrom($client);
        }

        // 2. Sync Clients TO RMM
        if (!empty($config['client_sync_to'])) {
            $this->syncClientsTo($client);
        }

        // 3. Sync Sites FROM RMM
        if (!empty($config['site_sync_from'])) {
            $this->syncSitesFrom($client);
        }

        // 4. Sync Sites TO RMM
        if (!empty($config['site_sync_to'])) {
            $this->syncSitesTo($client);
        }

        // 5. Sync Assets FROM RMM
        if (!empty($config['asset_sync_from'])) {
            $this->syncAssetsFrom($client);
        }

        $integration->last_sync_at = now();
        $integration->save();
    }

    /**
     * Imports and links clients from N-able RMM to the local database.
     *
     * @param NAbleRmmClient $rmmClient
     */
    protected function syncClientsFrom(NAbleRmmClient $rmmClient): void
    {
        $integration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        if (!$integration) return;

        $results = $rmmClient->listClients();
        if (isset($results['error'])) {
            Log::error('N-able RMM Sync Job (Clients From): ' . $results['error']);
            return;
        }

        foreach ($results as $item) {
            $localClient = Client::whereHas('rmmLinks', function($q) use ($integration, $item) {
                    $q->where('integration_id', $integration->id)
                      ->where('external_id', $item['clientid']);
                })
                ->orWhere('name', $item['name'])
                ->first();

            if ($localClient) {
                if ($localClient->name !== $item['name']) {
                    $localClient->name = $item['name'];
                    $localClient->save();
                }
            } else {
                $suggestedClientNumber = $this->generateClientNumber();
                $localClient = Client::create([
                    'name' => $item['name'],
                    'client_number' => $suggestedClientNumber,
                    'active' => true,
                ]);

                ClientSite::create([
                    'client_id' => $localClient->id,
                    'name' => 'Default',
                    'is_default' => true,
                ]);
            }

            // Ensure the RMM link exists
            \App\Models\System\Integrations\ClientRmmLink::updateOrCreate(
                [
                    'integration_id' => $integration->id,
                    'external_id' => $item['clientid'],
                    'linkable_type' => Client::class,
                ],
                [
                    'linkable_id' => $localClient->id,
                ]
            );
        }
    }

    /**
     * Exports and links unmapped local clients to N-able RMM.
     *
     * @param NAbleRmmClient $rmmClient
     */
    protected function syncClientsTo(NAbleRmmClient $rmmClient): void
    {
        $integration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        if (!$integration) return;

        $localClients = Client::whereDoesntHave('rmmLinks', function($q) use ($integration) {
                $q->where('integration_id', $integration->id);
            })->where('active', true)->get();

        foreach ($localClients as $localClient) {
            $result = $rmmClient->addClient($localClient->name);
            if ($result['success'] && isset($result['clientid'])) {
                ClientRmmLink::updateOrCreate(
                    [
                        'integration_id' => $integration->id,
                        'external_id' => $result['clientid'],
                        'linkable_type' => Client::class,
                    ],
                    [
                        'linkable_id' => $localClient->id,
                    ]
                );
            }
        }
    }

    /**
     * Imports and links sites from N-able RMM for all linked clients.
     *
     * @param NAbleRmmClient $rmmClient
     */
    protected function syncSitesFrom(NAbleRmmClient $rmmClient): void
    {
        $integration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        if (!$integration) return;

        $clients = Client::whereHas('rmmLinks', function($q) use ($integration) {
            $q->where('integration_id', $integration->id);
        })->where('active', true)->get();

        foreach ($clients as $localClient) {
            $clientLink = $localClient->rmmLinks()->where('integration_id', $integration->id)->first();
            if (!$clientLink) continue;

            $rmmSites = $rmmClient->listSites($clientLink->external_id);
            if (isset($rmmSites['error'])) continue;

            foreach ($rmmSites as $rmmSite) {
                $localSite = ClientSite::where('client_id', $localClient->id)
                    ->where(function($q) use ($integration, $rmmSite) {
                        $q->whereHas('rmmLinks', function($sq) use ($integration, $rmmSite) {
                            $sq->where('integration_id', $integration->id)
                               ->where('external_id', $rmmSite['siteid']);
                        })->orWhere('name', $rmmSite['name']);
                    })->first();

                if ($localSite) {
                    if ($localSite->name !== $rmmSite['name']) {
                        $localSite->name = $rmmSite['name'];
                        $localSite->save();
                    }
                } else {
                    $localSite = ClientSite::create([
                        'client_id' => $localClient->id,
                        'name' => $rmmSite['name'],
                    ]);
                }

                ClientRmmLink::updateOrCreate(
                    [
                        'integration_id' => $integration->id,
                        'external_id' => $rmmSite['siteid'],
                        'linkable_type' => ClientSite::class,
                    ],
                    [
                        'linkable_id' => $localSite->id,
                    ]
                );
            }
        }
    }

    /**
     * Imports and links assets from N-able RMM for all linked clients and sites.
     *
     * @param NAbleRmmClient $rmmClient
     */
    protected function syncAssetsFrom(NAbleRmmClient $rmmClient): void
    {
        $integration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        if (!$integration) return;

        $clients = Client::whereHas('rmmLinks', function($q) use ($integration) {
            $q->where('integration_id', $integration->id);
        })->where('active', true)->get();

        $deviceTypes = ['server', 'workstation'];

        foreach ($clients as $localClient) {
            $clientLink = $localClient->rmmLinks()->where('integration_id', $integration->id)->first();
            if (!$clientLink) continue;

            foreach ($deviceTypes as $type) {
                $rmmDevices = $rmmClient->listDevices($clientLink->external_id, $type);
                if (isset($rmmDevices['error'])) continue;

                foreach ($rmmDevices as $rmmDevice) {
                    if (isset($rmmDevice['error']) || !isset($rmmDevice['deviceid'])) continue;
                    if (empty($rmmDevice['siteid'])) continue;

                    $siteLink = ClientRmmLink::where('integration_id', $integration->id)
                        ->where('linkable_type', ClientSite::class)
                        ->where('external_id', (string)$rmmDevice['siteid'])
                        ->first();

                    if (!$siteLink) continue;
                    $localSite = $siteLink->linkable;

                    $asset = Asset::whereHas('rmmLinks', function($query) use ($integration, $rmmDevice) {
                        $query->where('integration_id', $integration->id)
                            ->where('external_id', (string)$rmmDevice['deviceid']);
                    })->first();

                    if (!$asset) {
                        // Fallback match within client
                        $asset = Asset::where('client_id', $localClient->id)
                            ->where(function($query) use ($rmmDevice) {
                                if (!empty($rmmDevice['name'])) {
                                    $query->where('hostname', $rmmDevice['name']);
                                }
                                if (!empty($rmmDevice['serial_number'])) {
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
                            'rmm_type' => $type
                        ]
                    ];

                    if ($asset) {
                        $asset->update($assetData);
                    } else {
                        $asset = Asset::create($assetData);
                    }

                    // Link the asset
                    ClientRmmLink::updateOrCreate(
                        [
                            'integration_id' => $integration->id,
                            'linkable_type' => Asset::class,
                            'linkable_id' => $asset->id,
                        ],
                        [
                            'external_id' => (string)$rmmDevice['deviceid'],
                        ]
                    );
                }
            }
        }
    }

    /**
     * Exports and links unmapped local sites to N-able RMM for all linked clients.
     *
     * @param NAbleRmmClient $rmmClient
     */
    protected function syncSitesTo(NAbleRmmClient $rmmClient): void
    {
        $integration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        if (!$integration) return;

        $clients = Client::whereHas('rmmLinks', function($q) use ($integration) {
            $q->where('integration_id', $integration->id);
        })->where('active', true)->get();

        foreach ($clients as $localClient) {
            $clientLink = $localClient->rmmLinks()->where('integration_id', $integration->id)->first();
            if (!$clientLink) continue;

            $localSites = ClientSite::where('client_id', $localClient->id)
                ->whereDoesntHave('rmmLinks', function($q) use ($integration) {
                    $q->where('integration_id', $integration->id);
                })
                ->get();

            foreach ($localSites as $localSite) {
                $result = $rmmClient->addSite($clientLink->external_id, $localSite->name);
                if ($result['success'] && isset($result['siteid'])) {
                    ClientRmmLink::updateOrCreate(
                        [
                            'integration_id' => $integration->id,
                            'external_id' => $result['siteid'],
                            'linkable_type' => ClientSite::class,
                        ],
                        [
                            'linkable_id' => $localSite->id,
                        ]
                    );
                }
            }
        }
    }

    /**
     * Generates a unique 5-digit client number.
     *
     * @return string|null
     */
    protected function generateClientNumber(): ?string
    {
        $suggestedClientNumber = null;
        $attempts = 0;
        while (!$suggestedClientNumber && $attempts < 10) {
            $maxNumber = Client::max('client_number') ?? 0;
            $nextNumber = str_pad((string) (((int) $maxNumber) + 1), 5, '0', STR_PAD_LEFT);
            if (!Client::where('client_number', $nextNumber)->exists()) {
                $suggestedClientNumber = $nextNumber;
            }
            $attempts++;
        }
        return $suggestedClientNumber;
    }
}
