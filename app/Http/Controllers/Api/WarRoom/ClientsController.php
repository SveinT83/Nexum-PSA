<?php

namespace App\Http\Controllers\Api\WarRoom;

use App\Http\Controllers\Controller;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use App\Services\Integrations\TacticalRmm\TacticalRmmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WarRoom Clients Controller
 *
 * Provides API endpoints for War Room dashboard to fetch
 * client data from multiple RMM sources through Nexum PSA.
 *
 * @package App\Http\Controllers\Api\WarRoom
 */
class ClientsController extends Controller
{
    /**
     * List all clients from configured RMM sources
     *
     * GET /api/warroom/clients?source=[nable|tacticalrmm|all]
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $source = $request->query('source', 'all');
        $allClients = [];
        $sourcesUsed = [];
        $errors = [];

        // Get clients from TacticalRMM if requested
        if (in_array($source, ['all', 'tacticalrmm'])) {
            $tacticalClient = new TacticalRmmClient();
            
            if ($tacticalClient->isConfigured()) {
                $result = $tacticalClient->getClients();
                
                if ($result['success']) {
                    $transformed = array_map(function ($client) {
                        return [
                            'id' => 'trmm_' . $client['id'],
                            'name' => $client['name'],
                            'device_counts' => [
                                'servers' => 0, // Will be populated per site
                                'workstations' => 0,
                                'mobile' => 0,
                                'total' => $client['agent_count'] ?? 0,
                            ],
                            'timezone' => $client['timezone'] ?? 'UTC',
                            'created_at' => $client['created_at'] ?? null,
                            'source_id' => $client['id'],
                            'nexum_source' => 'tacticalrmm',
                        ];
                    }, $result['clients']);
                    
                    $allClients = array_merge($allClients, $transformed);
                    $sourcesUsed[] = 'tacticalrmm';
                } else {
                    $errors['tacticalrmm'] = $result['error'];
                }
            }
        }

        // Get clients from N-Able RMM if requested
        if (in_array($source, ['all', 'nable'])) {
            $nableClient = new NAbleRmmClient();
            
            if ($nableClient->isConfigured()) {
                $result = $nableClient->listClients();
                
                if (!isset($result['error'])) {
                    $transformed = array_map(function ($client) {
                        return [
                            'id' => 'nable_' . $client['clientid'],
                            'name' => $client['name'],
                            'device_counts' => [
                                'servers' => (int) ($client['server_count'] ?? 0),
                                'workstations' => (int) ($client['workstation_count'] ?? 0),
                                'mobile' => (int) ($client['mobile_device_count'] ?? 0),
                                'total' => (int) ($client['device_count'] ?? 0),
                            ],
                            'timezone' => $client['timezone'] ?? 'UTC',
                            'created_at' => $client['creation_date'] ?? null,
                            'source_id' => $client['clientid'],
                            'nexum_source' => 'nable_rmm',
                        ];
                    }, $result);
                    
                    $allClients = array_merge($allClients, $transformed);
                    $sourcesUsed[] = 'nable_rmm';
                } else {
                    $errors['nable_rmm'] = $result['error'];
                }
            }
        }

        // Check if we have any data
        if (empty($allClients) && empty($sourcesUsed)) {
            return response()->json([
                'error' => 'No RMM integrations configured or available',
                'configured' => false,
                'errors' => $errors,
            ], 503);
        }

        return response()->json([
            'data' => $allClients,
            'meta' => [
                'count' => count($allClients),
                'sources' => $sourcesUsed,
                'cached_at' => now()->toIso8601String(),
                'errors' => $errors ?: null,
            ],
        ]);
    }

    /**
     * Get detailed client info
     *
     * GET /api/warroom/clients/{id}
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        // Parse source from ID prefix
        if (str_starts_with($id, 'trmm_')) {
            $sourceId = substr($id, 5);
            return $this->getTacticalClient($sourceId);
        } elseif (str_starts_with($id, 'nable_')) {
            $sourceId = substr($id, 6);
            return $this->getNableClient($sourceId);
        }

        // Try both sources if no prefix
        $tacticalClient = new TacticalRmmClient();
        if ($tacticalClient->isConfigured()) {
            return $this->getTacticalClient($id);
        }

        $nableClient = new NAbleRmmClient();
        if ($nableClient->isConfigured()) {
            return $this->getNableClient($id);
        }

        return response()->json([
            'error' => 'No RMM integration configured',
        ], 503);
    }

    /**
     * Get client details from TacticalRMM
     */
    private function getTacticalClient(int $id): JsonResponse
    {
        $client = new TacticalRmmClient();

        if (!$client->isConfigured()) {
            return response()->json([
                'error' => 'TacticalRMM integration not configured',
            ], 503);
        }

        // Get sites for this client
        $sitesResult = $client->getSites($id);
        $sites = $sitesResult['success'] ? $sitesResult['sites'] : [];

        // Get agents for this client
        $agentsResult = $client->getAgents(['client_id' => $id]);
        $agents = $agentsResult['success'] ? $agentsResult['agents'] : [];

        return response()->json([
            'data' => [
                'id' => 'trmm_' . $id,
                'source_id' => $id,
                'source' => 'tacticalrmm',
                'sites' => $sites,
                'agents' => $agents,
                'agent_count' => count($agents),
            ],
        ]);
    }

    /**
     * Get client details from N-Able RMM
     */
    private function getNableClient(string $id): JsonResponse
    {
        $client = new NAbleRmmClient();

        if (!$client->isConfigured()) {
            return response()->json([
                'error' => 'N-Able RMM integration not configured',
            ], 503);
        }

        // Get client sites
        $sites = $client->listSites($id);

        if (isset($sites['error'])) {
            return response()->json([
                'error' => $sites['error'],
            ], 502);
        }

        // Get client devices
        $servers = $client->listDevices($id, 'server');
        $workstations = $client->listDevices($id, 'workstation');

        return response()->json([
            'data' => [
                'id' => 'nable_' . $id,
                'source_id' => $id,
                'source' => 'nable_rmm',
                'sites' => $sites,
                'devices' => [
                    'servers' => isset($servers['error']) ? [] : $servers,
                    'workstations' => isset($workstations['error']) ? [] : $workstations,
                ],
            ],
        ]);
    }

    /**
     * Sync client to Nexum PSA
     *
     * POST /api/warroom/clients/{id}/sync
     *
     * @param string $id
     * @return JsonResponse
     */
    public function sync(string $id): JsonResponse
    {
        // TODO: Implement client sync from RMM sources to Nexum
        // This would create/update the client in Nexum PSA

        return response()->json([
            'message' => 'Sync not yet implemented',
            'client_id' => $id,
        ], 501);
    }
}
