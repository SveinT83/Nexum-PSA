<?php

namespace App\Jobs\Integrations;

use App\Models\System\Integrations\Integration;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
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
        $results = $rmmClient->listClients();
        if (isset($results['error'])) {
            Log::error('N-able RMM Sync Job (Clients From): ' . $results['error']);
            return;
        }

        foreach ($results as $item) {
            $localClient = Client::where('rmm_id', $item['clientid'])
                ->orWhere('name', $item['name'])
                ->first();

            if ($localClient) {
                $hasChanges = false;
                if (!$localClient->rmm_id) {
                    $localClient->rmm_id = $item['clientid'];
                    $hasChanges = true;
                }
                if ($localClient->name !== $item['name']) {
                    $localClient->name = $item['name'];
                    $hasChanges = true;
                }
                if ($hasChanges) {
                    $localClient->save();
                }
            } else {
                $suggestedClientNumber = $this->generateClientNumber();
                $newClient = Client::create([
                    'name' => $item['name'],
                    'client_number' => $suggestedClientNumber,
                    'rmm_id' => $item['clientid'],
                    'active' => true,
                ]);

                ClientSite::create([
                    'client_id' => $newClient->id,
                    'name' => 'Default',
                    'is_default' => true,
                ]);
            }
        }
    }

    /**
     * Exports and links unmapped local clients to N-able RMM.
     *
     * @param NAbleRmmClient $rmmClient
     */
    protected function syncClientsTo(NAbleRmmClient $rmmClient): void
    {
        $localClients = Client::whereNull('rmm_id')->where('active', true)->get();
        foreach ($localClients as $localClient) {
            $result = $rmmClient->addClient($localClient->name);
            if ($result['success'] && isset($result['clientid'])) {
                $localClient->rmm_id = $result['clientid'];
                $localClient->save();
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
        $clients = Client::whereNotNull('rmm_id')->where('active', true)->get();
        foreach ($clients as $localClient) {
            $rmmSites = $rmmClient->listSites($localClient->rmm_id);
            if (isset($rmmSites['error'])) continue;

            foreach ($rmmSites as $rmmSite) {
                $localSite = ClientSite::where('client_id', $localClient->id)
                    ->where(function($q) use ($rmmSite) {
                        $q->where('rmm_id', $rmmSite['siteid'])
                          ->orWhere('name', $rmmSite['name']);
                    })->first();

                if ($localSite) {
                    $hasChanges = false;
                    if (!$localSite->rmm_id) {
                        $localSite->rmm_id = $rmmSite['siteid'];
                        $hasChanges = true;
                    }
                    if ($localSite->name !== $rmmSite['name']) {
                        $localSite->name = $rmmSite['name'];
                        $hasChanges = true;
                    }
                    if ($hasChanges) $localSite->save();
                } else {
                    ClientSite::create([
                        'client_id' => $localClient->id,
                        'name' => $rmmSite['name'],
                        'rmm_id' => $rmmSite['siteid'],
                    ]);
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
        $clients = Client::whereNotNull('rmm_id')->where('active', true)->get();
        foreach ($clients as $localClient) {
            $localSites = ClientSite::where('client_id', $localClient->id)
                ->whereNull('rmm_id')
                ->get();

            foreach ($localSites as $localSite) {
                $result = $rmmClient->addSite($localClient->rmm_id, $localSite->name);
                if ($result['success'] && isset($result['siteid'])) {
                    $localSite->rmm_id = $result['siteid'];
                    $localSite->save();
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
