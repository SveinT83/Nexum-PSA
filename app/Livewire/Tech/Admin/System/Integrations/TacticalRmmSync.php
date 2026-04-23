<?php

namespace App\Livewire\Tech\Admin\System\Integrations;

use App\Jobs\Integrations\TacticalRmm\SyncTacticalAssetsJob;
use App\Jobs\Integrations\TacticalRmm\SyncTacticalClientsJob;
use App\Models\System\Integrations\ClientRmmLink;
use App\Models\System\Integrations\Integration;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Services\Integrations\TacticalRmm\TacticalRmmClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class TacticalRmmSync extends Component
{
    public $showModal = false;
    public $syncType = '';
    public $progress = 0;
    public $total = 0;
    public $current = 0;
    public $successCount = 0;
    public $updatedCount = 0;
    public $linkedCount = 0;
    public $errorCount = 0;
    public $errors = [];
    public $isComplete = false;
    public $isProcessing = false;
    public $batchSize = 10;
    public $offset = 0;
    public $itemsToProcess = [];
    public $isFetched = false;
    public $isFetching = false;
    public $targetSiteId = null;
    public $processingItemName = '';
    public $cacheKey = '';

    public $foundClientsCount = 0;
    public $foundSitesCount = 0;
    public $foundAssetsCount = 0;

    protected $listeners = ['startTacticalSync' => 'initSync', 'startTargetedTacticalSync' => 'initTargetedSync'];

    public function initTargetedSync($params = [])
    {
        Log::info('TacticalRmmSync: initTargetedSync started', ['params' => $params]);

        // Handle both nested and flat params
        if (isset($params['type'])) {
            $actualParams = $params;
        } elseif (isset($params['params'])) {
            $actualParams = $params['params'];
        } else {
            $actualParams = $params;
        }

        $this->syncType = $actualParams['type'] ?? 'assets_from';
        $this->targetSiteId = $actualParams['site_id'] ?? null;

        Log::info('TacticalRmmSync: Targeted syncType set to', [
            'syncType' => $this->syncType,
            'targetSiteId' => $this->targetSiteId
        ]);

        $this->resetProgress();
        $this->showModal = true;

        $this->fetchCounts();
    }

    public function initSync($params = [])
    {
        Log::info('TacticalRmmSync: initSync started', ['params' => $params]);

        // Standardize parameter extraction
        if (isset($params['params']['type'])) {
            $this->syncType = $params['params']['type'];
        } elseif (isset($params['type'])) {
            $this->syncType = $params['type'];
        } else {
            $this->syncType = 'clients_and_sites';
        }

        Log::info('TacticalRmmSync: syncType set to', ['syncType' => $this->syncType]);

        $this->resetProgress();
        $this->showModal = true;

        $this->fetchCounts();
    }

    public function fetchCounts()
    {
        $this->isFetching = true;
        $this->errors = [];

        $integration = Integration::where('type', 'tactical_rmm')->first();
        if (!$integration) {
            $this->errors[] = 'Tactical RMM integration not configured.';
            $this->isFetching = false;
            $this->isComplete = true;
            return;
        }

        try {
            $rmmClient = new TacticalRmmClient($integration->server, $integration->getSecret('api_key'));

            if ($this->syncType === 'clients_and_sites' || $this->syncType === 'clients_from' || $this->syncType === 'sites_from') {
                $clients = $rmmClient->getClients();
                $this->foundClientsCount = count($clients);
                $sitesCount = 0;
                foreach ($clients as $client) {
                    $sitesCount += count($client['sites'] ?? []);
                }
                $this->foundSitesCount = $sitesCount;
                $this->foundAssetsCount = 0; // Reset just in case
            } elseif ($this->syncType === 'assets_from') {
                $agents = $rmmClient->getAgents($this->targetSiteId);
                $this->foundAssetsCount = count($agents);
                $this->foundClientsCount = 0; // Reset just in case
                $this->foundSitesCount = 0; // Reset just in case
            }

            $this->isFetched = true;
        } catch (\Exception $e) {
            $this->errors[] = 'Failed to fetch data: ' . $e->getMessage();
        }

        $this->isFetching = false;

        // Auto-start sync if explicitly asked (can be used for automated tests or if we want to skip summary)
        // For now, we always show summary as requested by user.
    }

    public function startSync()
    {
        $integration = Integration::where('type', 'tactical_rmm')->first();
        if (!$integration) {
            $this->errors[] = 'Tactical RMM integration not configured.';
            $this->isComplete = true;
            return;
        }

        $this->cacheKey = 'tactical_sync_' . auth()->id() . '_' . time();
        $this->isProcessing = true;
        $this->isFetched = false;

        Log::info('TacticalRmmSync: Initializing sync', ['type' => $this->syncType, 'cacheKey' => $this->cacheKey]);

        // Pre-initialize cache
        Cache::put($this->cacheKey, [
            'current' => 0,
            'total' => 0,
            'results' => [
                'success' => 0,
                'updated' => 0,
                'linked' => 0,
                'errors' => 0,
                'error_list' => []
            ],
            'is_complete' => false
        ], now()->addMinutes(30));

        // Trigger the actual processing in the next request to allow UI to update
        $this->dispatch('beginTacticalProcessing');

        // Start checking progress immediately
        $this->dispatch('checkTacticalProgress');
    }

    public function processSync()
    {
        if (!$this->isProcessing || !$this->cacheKey) {
            return;
        }

        // Write session and close to prevent session blocking
        if (session_id()) {
            session_write_close();
        }

        $integration = Integration::where('type', 'tactical_rmm')->first();
        if (!$integration) {
            $this->errors[] = 'Tactical RMM integration not configured.';
            $this->isProcessing = false;
            $this->isComplete = true;
            return;
        }

        // If we haven't fetched the items yet, do it now
        if (empty($this->itemsToProcess) && $this->offset === 0) {
            $rmmClient = new TacticalRmmClient($integration->server, $integration->getSecret('api_key'));
            if ($this->syncType === 'assets_from') {
                $this->itemsToProcess = $rmmClient->getAgents($this->targetSiteId);
            } elseif ($this->syncType === 'clients_and_sites' || $this->syncType === 'clients_from' || $this->syncType === 'sites_from') {
                $this->itemsToProcess = $rmmClient->getClients();
            }

            if (!is_array($this->itemsToProcess)) {
                $this->errors[] = 'Failed to fetch data from Tactical RMM.';
                $this->isProcessing = false;
                $this->isComplete = true;
                return;
            }

            $this->total = count($this->itemsToProcess);

            // Initial progress update
            Cache::put($this->cacheKey, [
                'current' => 0,
                'total' => $this->total,
                'results' => [
                    'success' => 0, 'updated' => 0, 'linked' => 0, 'errors' => 0, 'error_list' => []
                ],
                'is_complete' => false
            ], now()->addMinutes(30));
        }

        $chunk = array_slice($this->itemsToProcess, $this->offset, $this->batchSize);

        if (empty($chunk)) {
            $this->isProcessing = false;
            $this->isComplete = true;
            $data = Cache::get($this->cacheKey);
            if ($data) {
                $data['is_complete'] = true;
                Cache::put($this->cacheKey, $data, now()->addMinutes(30));
            }
            $integration->update(['last_sync_at' => now()]);
            return;
        }

        try {
            if ($this->syncType === 'assets_from') {
                $job = new SyncTacticalAssetsJob($integration->id, $this->cacheKey, $this->targetSiteId);
                $this->processAssetsChunk($job, $chunk, $integration);
            } else {
                $job = new SyncTacticalClientsJob($integration->id, $this->cacheKey);
                $this->processClientsChunk($job, $chunk, $integration);
            }

            $this->offset += count($chunk);

            if ($this->offset >= $this->total) {
                $this->isProcessing = false;
                $this->isComplete = true;
                $data = Cache::get($this->cacheKey);
                if ($data) {
                    $data['is_complete'] = true;
                    Cache::put($this->cacheKey, $data, now()->addMinutes(30));
                }
                $integration->update(['last_sync_at' => now()]);
            } else {
                // Continue in next request
                $this->dispatch('beginTacticalProcessing');
            }

            $this->checkProgress();
        } catch (\Exception $e) {
            Log::error('TacticalRmmSync: Sync failed during chunk processing', ['error' => $e->getMessage()]);
            $this->errors[] = 'Sync failed: ' . $e->getMessage();
            $this->isProcessing = false;
            $this->isComplete = true;
        }
    }

    protected function processAssetsChunk($job, $chunk, $integration)
    {
        $data = Cache::get($this->cacheKey);
        $results = $data['results'];
        $current = $this->offset;
        $total = $this->total;

        $rmmClient = new TacticalRmmClient($integration->server, $integration->getSecret('api_key'));

        foreach ($chunk as $agentData) {
            try {
                // Resolution logic (copy/paste or make public in Job)
                if (!isset($agentData['client']) || !isset($agentData['site'])) {
                    $agentId = $agentData['agent_id'] ?? $agentData['pk'] ?? null;
                    if ($agentId) {
                        $fullAgent = $rmmClient->getAgentDetails($agentId);
                        if (!empty($fullAgent)) {
                            $agentData = array_merge($agentData, $fullAgent);
                        }
                    }
                }

                $job->processAgent($agentData, $integration, $results);
            } catch (\Exception $e) {
                $results['errors']++;
                $results['error_list'][] = [
                    'external_id' => $agentData['hostname'] ?? $agentData['pk'] ?? 'Unknown',
                    'message' => $e->getMessage()
                ];
            }
            $current++;

            // Update cache after each item in the chunk for better responsiveness
            Cache::put($this->cacheKey, [
                'current' => $current,
                'total' => $total,
                'results' => $results,
                'is_complete' => false,
                'item_name' => $agentData['hostname'] ?? $agentData['name'] ?? null
            ], now()->addMinutes(30));
        }
    }

    protected function processClientsChunk($job, $chunk, $integration)
    {
        $data = Cache::get($this->cacheKey);
        $results = $data['results'];
        $current = $this->offset;
        $total = $this->total;

        foreach ($chunk as $rmmClientData) {
            try {
                $job->syncClient($rmmClientData, $integration, $results);
            } catch (\Exception $e) {
                $results['errors']++;
                $results['error_list'][] = "Error syncing client " . ($rmmClientData['name'] ?? 'Unknown') . ": " . $e->getMessage();
            }
            $current++;

            Cache::put($this->cacheKey, [
                'current' => $current,
                'total' => $total,
                'results' => $results,
                'is_complete' => false,
                'item_name' => $rmmClientData['name'] ?? null
            ], now()->addMinutes(30));
        }
    }

    public function checkProgress()
    {
        // Re-open session to read latest data if needed,
        // but Cache is usually global and doesn't need session.
        if (!$this->cacheKey) return;

        $data = Cache::get($this->cacheKey);
        if ($data) {
            $this->current = $data['current'] ?? 0;
            $this->total = $data['total'] ?? 0;
            $this->processingItemName = $data['item_name'] ?? '';
            $this->successCount = $data['results']['success'] ?? 0;
            $this->updatedCount = $data['results']['updated'] ?? 0;
            $this->linkedCount = $data['results']['linked'] ?? 0;
            $this->errorCount = $data['results']['errors'] ?? 0;

            // Format errors if they are structured objects (assets job)
            $this->errors = [];
            foreach (($data['results']['error_list'] ?? []) as $err) {
                if (is_array($err)) {
                    $this->errors[] = "Asset {$err['external_id']}: {$err['message']}";
                } else {
                    $this->errors[] = $err;
                }
            }

            $this->progress = $this->total > 0 ? round(($this->current / $this->total) * 100) : 0;

            if ($data['is_complete'] ?? false) {
                $this->isProcessing = false;
                $this->isComplete = true;
            }
        }

        if ($this->isProcessing) {
            $this->dispatch('checkTacticalProgress');
        }
    }

    public function cancelSync()
    {
        $this->isProcessing = false;
        $this->isFetched = true; // Go back to summary
    }

    public function resetProgress()
    {
        $this->progress = 0;
        $this->total = 0;
        $this->current = 0;
        $this->offset = 0;
        $this->itemsToProcess = [];
        $this->successCount = 0;
        $this->updatedCount = 0;
        $this->linkedCount = 0;
        $this->errorCount = 0;
        $this->errors = [];
        $this->isComplete = false;
        $this->isProcessing = false;
        $this->isFetched = false;
        $this->isFetching = false;
        $this->targetSiteId = null;
        $this->processingItemName = '';
        $this->cacheKey = '';
        $this->foundClientsCount = 0;
        $this->foundSitesCount = 0;
        $this->foundAssetsCount = 0;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetProgress();
        return redirect(request()->header('Referer'));
    }

    public function render()
    {
        return view('livewire.tech.admin.system.integrations.tactical-rmm-sync');
    }
}
