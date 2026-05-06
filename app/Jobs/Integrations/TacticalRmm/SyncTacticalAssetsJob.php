<?php

namespace App\Jobs\Integrations\TacticalRmm;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\ClientRmmLink;
use App\Models\System\Integrations\Integration;
use App\Models\Tech\Work\Assets\Asset;
use App\Services\Integrations\TacticalRmm\TacticalRmmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncTacticalAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $integrationId;
    protected $cacheKey;
    protected $targetSiteId;

    public function __construct($integrationId, $cacheKey = null, $targetSiteId = null)
    {
        $this->integrationId = $integrationId;
        $this->cacheKey = $cacheKey;
        $this->targetSiteId = $targetSiteId;
    }

    public function handle()
    {
        $integration = Integration::find($this->integrationId);
        if (!$integration) {
            Log::error('SyncTacticalAssetsJob: Integration not found', ['id' => $this->integrationId]);
            return;
        }

        $rmmClient = new TacticalRmmClient($integration->server, $integration->getSecret('api_key'));

        $agents = $rmmClient->getAgents($this->targetSiteId);

        if (!is_array($agents)) {
            Log::error('SyncTacticalAssetsJob: API response is not an array');
            return;
        }

        $total = count($agents);
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

        foreach ($agents as $agentData) {
            try {
                // If IDs are missing, try to get full details for better resolution
                if (!isset($agentData['client']) || !isset($agentData['site'])) {
                    $agentId = $agentData['agent_id'] ?? $agentData['pk'] ?? null;
                    if ($agentId) {
                        $fullAgent = $rmmClient->getAgentDetails($agentId);
                        if (!empty($fullAgent)) {
                            $agentData = array_merge($agentData, $fullAgent);
                        }
                    }
                }

                $this->processAgent($agentData, $integration, $results);
            } catch (\Exception $e) {
                $results['errors']++;
                $results['error_list'][] = [
                    'external_id' => $agentData['hostname'] ?? $agentData['pk'] ?? 'Unknown',
                    'message' => $e->getMessage()
                ];
            }

            $current++;
            if ($this->cacheKey) {
                $this->updateProgress($current, $total, $results, false, $agentData['hostname'] ?? $agentData['name'] ?? null);
            }
        }

        if ($this->cacheKey) {
            $this->updateProgress($total, $total, $results, true);
        }

        $integration->update(['last_sync_at' => now()]);
    }

    public function processAgent($agentData, $integration, &$results)
    {
        // 1. Resolve Client & Sites (STRICT: ONLY use external_id from RMM Link)
        $localClient = $this->resolveClient($agentData, $integration);
        $localSite = $this->resolveSite($agentData, $integration);

        if (!$localClient || !$localSite) {
            $reason = !$localClient ? "Client mapping missing" : "Sites mapping missing";
            throw new \Exception($reason);
        }

        // Apply target site filter if present
        if ($this->targetSiteId && $localSite->id != $this->targetSiteId) {
            return;
        }

        // 2. Resolve Asset
        $agentExternalId = (string)($agentData['agent_id'] ?? $agentData['pk']);
        $existingLink = $this->findExistingLink($agentExternalId, $integration);
        $asset = $existingLink ? $existingLink->linkable : null;

        // Fallback: Match by hostname within the same client if no direct link exists
        if (!$asset && isset($agentData['hostname'])) {
            $asset = Asset::where('client_id', $localClient->id)
                ->where('hostname', $agentData['hostname'])
                ->first();
        }

        // 3. Map Data
        $assetData = $this->mapAgentToAssetData($agentData, $localClient, $localSite);

        // 4. Save Asset
        if (!$asset) {
            $asset = Asset::create($assetData);

            if ($existingLink) {
                // If link exists but asset was missing, update the link
                $existingLink->update([
                    'linkable_id' => $asset->id,
                    'linkable_type' => 'App\Models\Tech\Work\Assets\Asset'
                ]);
                $results['updated']++;
            } else {
                $this->createRmmLink($asset, $agentExternalId, $integration);
                $results['success']++;
            }
        } else {
            $asset->update($assetData);
            $results['updated']++;
        }
    }

    public function resolveClient($agentData, $integration)
    {
        $rmmClientId = $agentData['client'] ?? null;
        if ($rmmClientId) {
            $link = ClientRmmLink::where('integration_id', $integration->id)
                ->where('external_id', (string)$rmmClientId)
                ->where('linkable_type', 'App\Models\Clients\Client')
                ->first();

            if ($link) return $link->linkable;
        }

        // Fallback to name-based resolution if ID is missing or link not found
        $clientName = $agentData['client_name'] ?? null;
        if (!$clientName) return null;

        // Try exact name match
        $client = Client::where('name', $clientName)->first();
        if ($client) return $client;

        // Try name without prefix (e.g. "12345 - Client Name")
        if (preg_match('/^\d+\s*-\s*(.*)$/', $clientName, $matches)) {
            $strippedName = trim($matches[1]);
            $client = Client::where('name', $strippedName)->first();
            if ($client) return $client;
        }

        return null;
    }

    public function resolveSite($agentData, $integration)
    {
        $rmmSiteId = $agentData['site'] ?? null;
        if ($rmmSiteId) {
            $link = ClientRmmLink::where('integration_id', $integration->id)
                ->where('external_id', (string)$rmmSiteId)
                ->where('linkable_type', 'App\Models\Clients\ClientSite')
                ->first();

            if ($link) return $link->linkable;
        }

        // Fallback to name-based resolution for site
        $siteName = $agentData['site_name'] ?? null;
        $clientName = $agentData['client_name'] ?? null;
        if (!$siteName || !$clientName) return null;

        $localClient = $this->resolveClient($agentData, $integration);
        if (!$localClient) return null;

        return ClientSite::where('client_id', $localClient->id)
            ->where('name', $siteName)
            ->first();
    }

    protected function findExistingLink($externalId, $integration)
    {
        return ClientRmmLink::where('integration_id', $integration->id)
            ->where('external_id', (string)$externalId)
            ->where('linkable_type', 'App\Models\Tech\Work\Assets\Asset')
            ->first();
    }

    protected function createRmmLink($asset, $externalId, $integration)
    {
        ClientRmmLink::create([
            'integration_id' => $integration->id,
            'external_id' => $externalId,
            'linkable_type' => 'App\Models\Tech\Work\Assets\Asset',
            'linkable_id' => $asset->id,
        ]);
    }

    protected function mapAgentToAssetData($agentData, $localClient, $localSite)
    {
        $ipAddress = null;
        if (isset($agentData['local_ips'])) {
            $ips = is_array($agentData['local_ips']) ? $agentData['local_ips'] : explode(',', $agentData['local_ips']);
            $firstIp = trim($ips[0] ?? '');
            if ($firstIp) {
                $ipAddress = explode('/', $firstIp)[0];
            }
        }

        // Asset Type Detection
        $type = 'pc';
        $monitoringType = strtolower($agentData['monitoring_type'] ?? '');
        if ($monitoringType === 'server') {
            $type = 'server';
        } elseif ($monitoringType === 'workstation') {
            $type = 'pc';
        }

        return [
            'client_id' => $localClient->id,
            'site_id' => $localSite->id,
            'name' => $agentData['hostname'],
            'type' => $type,
            'model' => $agentData['make_model'] ?? $agentData['model'] ?? null,
            'serial_number' => (isset($agentData['serial_number']) && $agentData['serial_number'] !== 'unknown') ? $agentData['serial_number'] : null,
            'mac_address' => is_array($agentData['mac_addresses'] ?? null) ? ($agentData['mac_addresses'][0] ?? null) : null,
            'ip_address' => $ipAddress,
            'hostname' => $agentData['hostname'] ?? null,
            'source' => 'tactical_rmm',
            'status' => $agentData['status'] ?? 'unknown',
            'last_seen_at' => isset($agentData['last_seen']) ? \Carbon\Carbon::parse($agentData['last_seen']) : null,
            'is_managed' => true,
        ];
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
}
