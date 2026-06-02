<?php

namespace App\Console\Commands\Integrations;

use App\Models\System\Integrations\Integration;
use App\Models\System\Integrations\ClientRmmLink;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Tech\Work\Assets\Asset;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NAbleRmmSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'integrations:nable-rmm-sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronizes assets, clients, and sites from N-able RMM based on integration settings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $integration = Integration::where('type', 'rmm')->where('status', 'active')->first();

        if (!$integration) {
            $this->info('N-able RMM integration is not active. Skipping sync.');
            return 0;
        }

        $config = $integration->config ?? [];
        $client = new NAbleRmmClient($integration);

        // 1. Sync Clients From RMM (if enabled)
        if (!empty($config['client_sync_from'])) {
            $this->syncClientsFrom($client);
        }

        // 2. Sync Sites From RMM (if enabled)
        if (!empty($config['site_sync_from'])) {
            $this->syncSitesFrom($client);
        }

        // 3. Sync Assets From RMM (if enabled)
        if (!empty($config['asset_sync_from'])) {
            $this->syncAssetsFrom($client);
        }

        $integration->last_sync_at = now();
        $integration->save();

        $this->info('N-able RMM synchronization completed.');
        return 0;
    }

    protected function syncClientsFrom($rmmClient)
    {
        $this->info('Syncing clients from RMM...');
        $integration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        if (!$integration) return;

        $response = $rmmClient->listClients();

        if (isset($response['error'])) {
            Log::error('NAbleRmmSyncCommand: Failed to fetch clients: ' . $response['error']);
            return;
        }

        foreach ($response as $item) {
            if (isset($item['error']) || !isset($item['clientid'])) continue;

            $localClient = Client::whereHas('rmmLinks', function($q) use ($integration, $item) {
                    $q->where('integration_id', $integration->id)
                      ->where('external_id', $item['clientid']);
                })
                ->orWhere('name', $item['name'])
                ->first();

            if (!$localClient) {
                $localClient = Client::create([
                    'name' => $item['name'],
                    'client_number' => $this->generateClientNumber()
                ]);

                ClientSite::create([
                    'client_id' => $localClient->id,
                    'name' => 'Default',
                    'is_default' => true,
                ]);
            } else {
                if ($localClient->name !== $item['name']) {
                    $localClient->update(['name' => $item['name']]);
                }
            }

            \App\Models\System\Integrations\ClientRmmLink::updateOrCreate(
                [
                    'integration_id' => $integration->id,
                    'external_id' => (string)$item['clientid'],
                    'linkable_type' => Client::class,
                ],
                [
                    'linkable_id' => $localClient->id,
                ]
            );
        }
    }

    protected function syncSitesFrom($rmmClient)
    {
        $this->info('Syncing sites from RMM...');
        $integration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        if (!$integration) return;

        $clients = Client::whereHas('rmmLinks', function($q) use ($integration) {
            $q->where('integration_id', $integration->id);
        })->get();

        foreach ($clients as $localClient) {
            $clientLink = $localClient->rmmLinks()->where('integration_id', $integration->id)->first();
            if (!$clientLink) continue;

            $response = $rmmClient->listSites($clientLink->external_id);
            if (isset($response['error'])) continue;

            foreach ($response as $item) {
                if (isset($item['error']) || !isset($item['siteid'])) continue;

                $localSite = ClientSite::where('client_id', $localClient->id)
                    ->where(function($q) use ($integration, $item) {
                        $q->whereHas('rmmLinks', function($sq) use ($integration, $item) {
                            $sq->where('integration_id', $integration->id)
                               ->where('external_id', (string)$item['siteid']);
                        })->orWhere('name', $item['name']);
                    })->first();

                if (!$localSite) {
                    $localSite = ClientSite::create([
                        'client_id' => $localClient->id,
                        'name' => $item['name']
                    ]);
                } else {
                    if ($localSite->name !== $item['name']) {
                        $localSite->update(['name' => $item['name']]);
                    }
                }

                \App\Models\System\Integrations\ClientRmmLink::updateOrCreate(
                    [
                        'integration_id' => $integration->id,
                        'external_id' => (string)$item['siteid'],
                        'linkable_type' => ClientSite::class,
                    ],
                    [
                        'linkable_id' => $localSite->id,
                    ]
                );
            }
        }
    }

    protected function syncAssetsFrom($rmmClient)
    {
        $this->info('Syncing assets from RMM...');
        $integration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        if (!$integration) return;

        $clients = Client::whereHas('rmmLinks', function($q) use ($integration) {
            $q->where('integration_id', $integration->id);
        })->get();

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

                    $localSite = $this->resolveLocalSiteForDevice($integration, $localClient, $rmmDevice);

                    if (!$localSite) continue;

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
                    \App\Models\System\Integrations\ClientRmmLink::updateOrCreate(
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

    protected function resolveLocalSiteForDevice(Integration $integration, Client $localClient, array $rmmDevice): ?ClientSite
    {
        $rmmSiteId = (string)($rmmDevice['siteid'] ?? '');

        if ($rmmSiteId === '') {
            return null;
        }

        $siteLink = ClientRmmLink::where('integration_id', $integration->id)
            ->where('linkable_type', ClientSite::class)
            ->where('external_id', $rmmSiteId)
            ->first();

        if ($siteLink && $siteLink->linkable instanceof ClientSite) {
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

    protected function generateClientNumber()
    {
        $maxNumber = Client::max('client_number') ?? 0;
        return str_pad((string) (((int) $maxNumber) + 1), 5, '0', STR_PAD_LEFT);
    }
}
