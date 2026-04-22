<?php

namespace App\Console\Commands\Integrations;

use App\Models\System\Integrations\Integration;
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
        $response = $rmmClient->listClients();

        if (isset($response['error'])) {
            Log::error('NAbleRmmSyncCommand: Failed to fetch clients: ' . $response['error']);
            return;
        }

        foreach ($response as $item) {
            if (isset($item['error']) || !isset($item['clientid'])) continue;

            Client::updateOrCreate(
                ['rmm_id' => (string)$item['clientid']],
                [
                    'name' => $item['name'],
                    // Only generate client number for new clients
                    'client_number' => Client::where('rmm_id', (string)$item['clientid'])->exists()
                        ? Client::where('rmm_id', (string)$item['clientid'])->first()->client_number
                        : $this->generateClientNumber()
                ]
            );
        }
    }

    protected function syncSitesFrom($rmmClient)
    {
        $this->info('Syncing sites from RMM...');
        $clients = Client::whereNotNull('rmm_id')->get();

        foreach ($clients as $localClient) {
            $response = $rmmClient->listSites($localClient->rmm_id);
            if (isset($response['error'])) continue;

            foreach ($response as $item) {
                if (isset($item['error']) || !isset($item['siteid'])) continue;

                ClientSite::updateOrCreate(
                    ['rmm_id' => (string)$item['siteid']],
                    [
                        'client_id' => $localClient->id,
                        'name' => $item['name']
                    ]
                );
            }
        }
    }

    protected function syncAssetsFrom($rmmClient)
    {
        $this->info('Syncing assets from RMM...');
        $clients = Client::whereNotNull('rmm_id')->get();
        $deviceTypes = ['server', 'workstation', 'mobile'];

        foreach ($clients as $localClient) {
            foreach ($deviceTypes as $type) {
                $rmmDevices = $rmmClient->listDevices($localClient->rmm_id, $type);
                if (isset($rmmDevices['error'])) continue;

                foreach ($rmmDevices as $rmmDevice) {
                    if (isset($rmmDevice['error']) || !isset($rmmDevice['deviceid'])) continue;
                    if (empty($rmmDevice['siteid'])) continue;

                    $localSite = ClientSite::where('client_id', $localClient->id)
                        ->where('rmm_id', (string)$rmmDevice['siteid'])
                        ->first();

                    if (!$localSite) continue;

                    Asset::updateOrCreate(
                        ['rmm_id' => (string)$rmmDevice['deviceid']],
                        [
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
                        ]
                    );
                }
            }
        }
    }

    protected function generateClientNumber()
    {
        $maxNumber = Client::max('client_number') ?? 0;
        return str_pad((string) (((int) $maxNumber) + 1), 5, '0', STR_PAD_LEFT);
    }
}
