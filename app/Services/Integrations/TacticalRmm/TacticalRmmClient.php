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
     * Generic GET request to Tactical RMM API.
     */
    protected function get(string $endpoint): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . $endpoint);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error("Tactical RMM API GET {$endpoint} failed: " . $response->status());
            return [];
        } catch (\Exception $e) {
            Log::error("Tactical RMM API GET {$endpoint} exception: " . $e->getMessage());
            return [];
        }
    }
}
