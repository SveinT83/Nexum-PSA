<?php

namespace App\Services\Integrations\TacticalRmm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TacticalRmmClient
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Test the connection to Tactical RMM API.
     *
     * @return array
     */
    public function testConnection(): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(10)->get($this->baseUrl . '/agents/?detail=false');

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Connected successfully. Found ' . (is_array($response->json()) ? count($response->json()) : 0) . ' agents.'
                ];
            }

            return [
                'success' => false,
                'message' => 'API Error: ' . ($response->json()['detail'] ?? $response->status())
            ];
        } catch (\Exception $e) {
            Log::error('Tactical RMM Connection Test Failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Connection Failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all clients from Tactical RMM.
     */
    public function getClients(): array
    {
        return $this->get('/clients/');
    }

    /**
     * Get all sites from Tactical RMM.
     */
    public function getSites(): array
    {
        return $this->get('/sites/');
    }

    /**
     * Get all agents from Tactical RMM.
     */
    public function getAgents(?int $siteId = null): array
    {
        $endpoint = '/agents/';
        if ($siteId) {
            $endpoint .= '?site=' . $siteId;
        }
        return $this->get($endpoint);
    }

    /**
     * Get details for a specific agent.
     */
    public function getAgentDetails(string $agentId): array
    {
        return $this->get("/agents/{$agentId}/");
    }

    /**
     * Generic GET request to Tactical RMM API.
     */
    protected function get(string $endpoint): array
    {
        try {
            $url = rtrim($this->baseUrl, "/") . "/" . ltrim($endpoint, "/");
            Log::info("Tactical RMM API GET requesting: " . $url);

            $response = Http::withHeaders([
                'X-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->get($url);

            if ($response->successful()) {
                $data = $response->json();
                Log::info("Tactical RMM API GET {$endpoint} success", [
                    'count' => is_array($data) ? count($data) : 'not an array',
                    'raw_sample' => substr($response->body(), 0, 500)
                ]);
                return is_array($data) ? $data : [];
            }

            Log::error("Tactical RMM API GET {$endpoint} failed: " . $response->status(), [
                'body' => $response->body(),
                'url' => $url
            ]);
            return [];
        } catch (\Exception $e) {
            Log::error("Tactical RMM API GET {$endpoint} exception: " . $e->getMessage());
            return [];
        }
    }
}
