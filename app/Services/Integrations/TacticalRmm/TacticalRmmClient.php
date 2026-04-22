<?php

namespace App\Services\Integrations\TacticalRmm;

use App\Models\System\Integrations\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TacticalRmmClient
 *
 * A service class for interacting with the TacticalRMM API.
 * Handles authentication, agent management, and client/site synchronization.
 * API documentation: https://docs.tacticalrmm.com/
 */
class TacticalRmmClient
{
    /** @var Integration|null The associated integration model instance */
    protected ?Integration $integration;

    /** @var string|null The base URL for the TacticalRMM instance */
    protected ?string $server;

    /** @var string|null The API key for authentication */
    protected ?string $apiKey;

    /**
     * Initializes the client with configuration from the 'tacticalrmm' integration.
     *
     * @param Integration|null $integration Optional integration model to override default lookup
     */
    public function __construct(?Integration $integration = null)
    {
        $this->integration = $integration ?? Integration::where('type', 'tacticalrmm')->first();
        $this->server = $this->integration?->server;
        $this->apiKey = $this->integration?->getSecret('api_key');
    }

    /**
     * Set credentials manually (useful for testing connection before saving)
     *
     * @param string $server
     * @param string $apiKey
     * @return $this
     */
    public function setCredentials(string $server, string $apiKey): self
    {
        $this->server = $server;
        $this->apiKey = $apiKey;
        return $this;
    }

    /**
     * Checks if the integration is fully configured (URL, Key, and Status).
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->integration && $this->server && $this->apiKey && $this->integration->status === 'active';
    }

    /**
     * Get the base API URL.
     *
     * @return string
     */
    protected function getApiUrl(): string
    {
        return rtrim($this->server, '/');
    }

    /**
     * Get HTTP client with authentication headers.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function httpClient()
    {
        return Http::withHeaders([
            'X-API-KEY' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(30);
    }

    /**
     * Test connection to TacticalRMM.
     *
     * @return array ['success' => bool, 'error' => ?string, 'data' => ?array]
     */
    public function testConnection(): array
    {
        if (!$this->server || !$this->apiKey) {
            return ['success' => false, 'error' => 'Server URL or API Key missing.'];
        }

        try {
            $response = $this->httpClient()->get($this->getApiUrl() . '/agents/');

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'data' => [
                        'total_agents' => count($data ?? []),
                    ]
                ];
            }

            // Try alternative path without trailing slash
            if ($response->status() === 404) {
                $response2 = $this->httpClient()->get($this->getApiUrl() . '/agents');
                if ($response2->successful()) {
                    $data = $response2->json();
                    return [
                        'success' => true,
                        'data' => [
                            'total_agents' => count($data ?? []),
                        ]
                    ];
                }
            }

            return [
                'success' => false,
                'error' => 'HTTP Error: ' . $response->status() . ' - ' . $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('TacticalRMM connection test failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get all clients from TacticalRMM.
     *
     * @return array ['success' => bool, 'clients' => array, 'error' => ?string]
     */
    public function getClients(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'clients' => [], 'error' => 'Integration not configured'];
        }

        try {
            $response = $this->httpClient()->get($this->getApiUrl() . '/clients/');

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'clients' => $data ?? [],
                ];
            }

            return [
                'success' => false,
                'clients' => [],
                'error' => 'HTTP Error: ' . $response->status()
            ];
        } catch (\Exception $e) {
            Log::error('TacticalRMM getClients failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'clients' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Get all sites for a client.
     *
     * @param int $clientId
     * @return array ['success' => bool, 'sites' => array, 'error' => ?string]
     */
    public function getSites(int $clientId): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'sites' => [], 'error' => 'Integration not configured'];
        }

        try {
            $response = $this->httpClient()->get($this->getApiUrl() . '/sites/', [
                'client_id' => $clientId,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'sites' => $data ?? [],
                ];
            }

            return [
                'success' => false,
                'sites' => [],
                'error' => 'HTTP Error: ' . $response->status()
            ];
        } catch (\Exception $e) {
            Log::error('TacticalRMM getSites failed', ['error' => $e->getMessage(), 'client_id' => $clientId]);
            return ['success' => false, 'sites' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Get all agents (with optional filtering).
     *
     * @param array $filters Optional filters like ['client_id' => 123, 'site_id' => 456]
     * @return array ['success' => bool, 'agents' => array, 'error' => ?string]
     */
    public function getAgents(array $filters = []): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'agents' => [], 'error' => 'Integration not configured'];
        }

        try {
            $response = $this->httpClient()->get($this->getApiUrl() . '/agents/', $filters);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'agents' => $data ?? [],
                ];
            }

            return [
                'success' => false,
                'agents' => [],
                'error' => 'HTTP Error: ' . $response->status()
            ];
        } catch (\Exception $e) {
            Log::error('TacticalRMM getAgents failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'agents' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Get detailed information about a specific agent.
     *
     * @param string $agentId
     * @return array ['success' => bool, 'agent' => ?array, 'error' => ?string]
     */
    public function getAgent(string $agentId): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'agent' => null, 'error' => 'Integration not configured'];
        }

        try {
            $response = $this->httpClient()->get($this->getApiUrl() . "/agents/{$agentId}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'agent' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'agent' => null,
                'error' => 'HTTP Error: ' . $response->status()
            ];
        } catch (\Exception $e) {
            Log::error('TacticalRMM getAgent failed', ['error' => $e->getMessage(), 'agent_id' => $agentId]);
            return ['success' => false, 'agent' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get agent checks/alerts.
     *
     * @param string $agentId
     * @return array ['success' => bool, 'checks' => array, 'error' => ?string]
     */
    public function getAgentChecks(string $agentId): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'checks' => [], 'error' => 'Integration not configured'];
        }

        try {
            $response = $this->httpClient()->get($this->getApiUrl() . "/agents/{$agentId}/checks");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'checks' => $response->json()['checks'] ?? [],
                ];
            }

            return [
                'success' => false,
                'checks' => [],
                'error' => 'HTTP Error: ' . $response->status()
            ];
        } catch (\Exception $e) {
            Log::error('TacticalRMM getAgentChecks failed', ['error' => $e->getMessage(), 'agent_id' => $agentId]);
            return ['success' => false, 'checks' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Sync all data from TacticalRMM to Nexum.
     * This is the main entry point for the War Room sync.
     *
     * @return array ['success' => bool, 'stats' => array, 'error' => ?string]
     */
    public function syncAll(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'stats' => [], 'error' => 'Integration not configured'];
        }

        $stats = [
            'clients_synced' => 0,
            'sites_synced' => 0,
            'agents_synced' => 0,
            'errors' => [],
        ];

        try {
            // Get all clients
            $clientsResult = $this->getClients();
            if (!$clientsResult['success']) {
                return ['success' => false, 'stats' => $stats, 'error' => $clientsResult['error']];
            }

            $clients = $clientsResult['clients'];
            $stats['clients_synced'] = count($clients);

            // For each client, get sites
            foreach ($clients as $client) {
                $sitesResult = $this->getSites($client['id']);
                if ($sitesResult['success']) {
                    $stats['sites_synced'] += count($sitesResult['sites']);
                }
            }

            // Get all agents
            $agentsResult = $this->getAgents();
            if ($agentsResult['success']) {
                $stats['agents_synced'] = count($agentsResult['agents']);
            }

            return [
                'success' => true,
                'stats' => $stats,
            ];
        } catch (\Exception $e) {
            Log::error('TacticalRMM syncAll failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'stats' => $stats, 'error' => $e->getMessage()];
        }
    }
}
