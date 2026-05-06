<?php

namespace App\Jobs\Integrations\TacticalRmm;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\ClientRmmLink;
use App\Models\System\Integrations\Integration;
use App\Services\Integrations\TacticalRmm\TacticalRmmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncTacticalClientsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $integrationId;
    protected $cacheKey;

    public function __construct($integrationId, $cacheKey = null)
    {
        $this->integrationId = $integrationId;
        $this->cacheKey = $cacheKey;
    }

    protected function updateProgress($current, $total, $results, $isComplete, $itemName = null)
    {
        Cache::put($this->cacheKey, [
            'current' => $current,
            'total' => $total,
            'results' => $results,
            'is_complete' => $isComplete,
            'item_name' => $itemName
        ], now()->addMinutes(30));
    }

    public function handle()
    {
        $integration = Integration::find($this->integrationId);
        if (!$integration) {
            Log::error('SyncTacticalClientsJob: Integration not found', ['id' => $this->integrationId]);
            return;
        }

        $rmmClient = new TacticalRmmClient($integration->server, $integration->getSecret('api_key'));

        $clients = $rmmClient->getClients();

        if (!is_array($clients)) {
            Log::error('SyncTacticalClientsJob: API response is not an array');
            return;
        }

        $total = count($clients);
        $current = 0;
        $results = [
            'success' => 0,
            'updated' => 0,
            'linked' => 0,
            'errors' => 0,
            'error_list' => []
        ];

        if ($this->cacheKey) {
            $this->updateProgress(0, $total, $results, false);
        }

        foreach ($clients as $rmmClientData) {
            try {
                $this->syncClient($rmmClientData, $integration, $results);
            } catch (\Exception $e) {
                $results['errors']++;
                $results['error_list'][] = "Error syncing client " . ($rmmClientData['name'] ?? 'Unknown') . ": " . $e->getMessage();
            }

            $current++;
            if ($this->cacheKey) {
                $this->updateProgress($current, $total, $results, false, $rmmClientData['name'] ?? null);
            }
        }

        if ($this->cacheKey) {
            $this->updateProgress($total, $total, $results, true);
        }

        $integration->update(['last_sync_at' => now()]);
    }

    public function syncClient($rmmClientData, $integration, &$results)
    {
        $externalId = (string)$rmmClientData['id'];

        $link = ClientRmmLink::where('integration_id', $integration->id)
            ->where('external_id', $externalId)
            ->where('linkable_type', 'App\Models\Clients\Client')
            ->first();

        $localClient = $link ? $link->linkable : null;

        if (!$localClient) {
            Log::info('SyncTacticalClientsJob: Client mapping missing or local record gone, handling client', ['external_id' => $externalId, 'name' => $rmmClientData['name']]);

            // Create the local client
            $localClient = Client::create([
                'name' => $rmmClientData['name'],
            ]);

            if ($link) {
                // Link existed but linkable was null (deleted record)
                $link->update(['linkable_id' => $localClient->id]);
                $results['linked']++;
            } else {
                // Create new link
                ClientRmmLink::create([
                    'integration_id' => $integration->id,
                    'external_id' => $externalId,
                    'linkable_type' => 'App\Models\Clients\Client',
                    'linkable_id' => $localClient->id,
                ]);
                $results['success']++;
            }
        } else {
            // Update name if changed
            if ($localClient->name !== $rmmClientData['name']) {
                $localClient->update(['name' => $rmmClientData['name']]);
                $results['updated']++;
            } else {
                $results['linked']++;
            }
        }

        // Sync Sites
        $this->syncSites($localClient, $rmmClientData['sites'] ?? [], $integration, $results);
    }

    protected function syncSites($localClient, $rmmSites, $integration, &$results)
    {
        foreach ($rmmSites as $rmmSiteData) {
            $externalId = (string)$rmmSiteData['id'];

            $link = ClientRmmLink::where('integration_id', $integration->id)
                ->where('external_id', $externalId)
                ->where('linkable_type', 'App\Models\Clients\ClientSite')
                ->first();

            $localSite = $link ? $link->linkable : null;

            if (!$localSite) {
                Log::info('SyncTacticalClientsJob: Sites mapping missing or local record gone, handling site', ['external_id' => $externalId, 'name' => $rmmSiteData['name'], 'client' => $localClient->name]);

                $localSite = ClientSite::create([
                    'client_id' => $localClient->id,
                    'name' => $rmmSiteData['name'],
                ]);

                if ($link) {
                    $link->update(['linkable_id' => $localSite->id]);
                } else {
                    ClientRmmLink::create([
                        'integration_id' => $integration->id,
                        'external_id' => $externalId,
                        'linkable_type' => 'App\Models\Clients\ClientSite',
                        'linkable_id' => $localSite->id,
                    ]);
                }

                continue;
            }

            if ($localSite->name !== $rmmSiteData['name']) {
                $localSite->update(['name' => $rmmSiteData['name']]);
            }
        }
    }
}
