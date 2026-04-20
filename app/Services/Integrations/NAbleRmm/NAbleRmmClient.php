<?php

namespace App\Services\Integrations\NAbleRmm;

use App\Models\System\Integrations\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NAbleRmmClient
 *
 * A service class for interacting with the N-able RMM API.
 * Handles authentication, client management, and site management.
 * API endpoints are built dynamically based on the configured server URL.
 */
class NAbleRmmClient
{
    /** @var Integration|null The associated integration model instance */
    protected ?Integration $integration;

    /** @var string|null The base URL for the N-able RMM instance */
    protected ?string $server;

    /** @var string|null The decrypted API key used for all requests */
    protected ?string $apiKey;

    /**
     * Initializes the client with configuration from the 'rmm' integration.
     *
     * @param Integration|null $integration Optional integration model to override default lookup
     */
    public function __construct(?Integration $integration = null)
    {
        $this->integration = $integration ?? Integration::where('type', 'rmm')->first();
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
     * Test connection to N-able RMM.
     *
     * @return array ['success' => bool, 'error' => ?string]
     */
    public function testConnection(): array
    {
        if (!$this->server || !$this->apiKey) {
            return ['success' => false, 'error' => 'Server URL or API Key missing.'];
        }

        try {
            $url = rtrim($this->server, '/') . '/api/';
            $response = Http::get($url, [
                'apikey' => $this->apiKey,
                'service' => 'list_clients',
            ]);

            if ($response->successful()) {
                // Check if the XML says "OK"
                $xml = simplexml_load_string($response->body());
                if ($xml && (string) $xml->attributes()->status === 'OK') {
                    return ['success' => true];
                }
                return [
                    'success' => false,
                    'error' => $xml ? 'RMM Error: ' . (string) $xml->attributes()->status : 'Invalid XML response.'
                ];
            }

            return [
                'success' => false,
                'error' => 'HTTP Error: ' . $response->status()
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetches all clients from N-able RMM.
     * Uses the 'list_clients' service endpoint.
     *
     * @return array List of clients or error message
     */
    public function listClients(): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Integration not fully configured or active.'];
        }

        try {
            $url = rtrim($this->server, '/') . '/api/';
            Log::info('Fetching N-able RMM clients from: ' . $url);
            $response = Http::get($url, [
                'apikey' => $this->apiKey,
                'service' => 'list_clients',
            ]);

            if ($response->failed()) {
                $errorMsg = 'N-able RMM API error. Status: ' . $response->status();
                Log::error($errorMsg . ' Body: ' . $response->body());
                return ['error' => $errorMsg];
            }

            return $this->parseXmlClients($response->body());
        } catch (\Exception $e) {
            $errorMsg = 'N-able RMM API exception: ' . $e->getMessage();
            Log::error($errorMsg);
            return ['error' => $errorMsg];
        }
    }

    /**
     * Parse the XML response from list_clients.
     *
     * @param string $xmlString
     * @return array
     */
    protected function parseXmlClients(string $xmlString): array
    {
        try {
            $xml = simplexml_load_string($xmlString);
            if (!$xml) {
                Log::error('N-able RMM XML parse error: Could not parse XML.');
                return ['error' => 'Invalid XML response from RMM.'];
            }

            if ((string) $xml->attributes()->status !== 'OK') {
                $status = (string) $xml->attributes()->status;
                Log::error('N-able RMM API error status in XML: ' . $status);
                return ['error' => 'API returned status: ' . $status];
            }

            $clients = [];
            foreach ($xml->items->client as $client) {
                $clients[] = [
                    'name' => (string) $client->name,
                    'clientid' => (string) $client->clientid,
                ];
            }

            return $clients;
        } catch (\Exception $e) {
            $errorMsg = 'N-able RMM XML parse error: ' . $e->getMessage();
            Log::error($errorMsg);
            return ['error' => $errorMsg];
        }
    }

    /**
     * Add a client to N-able RMM.
     *
     * @param string $name
     * @return array ['success' => bool, 'error' => ?string, 'clientid' => ?string]
     */
    public function addClient(string $name): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Integration not fully configured or active.'];
        }

        try {
            $url = rtrim($this->server, '/') . '/api/';
            $response = Http::get($url, [
                'apikey' => $this->apiKey,
                'service' => 'add_client',
                'name' => $name,
            ]);

            if ($response->failed()) {
                return ['success' => false, 'error' => 'HTTP Error: ' . $response->status()];
            }

            $xml = simplexml_load_string($response->body());

            // Check success field in data node as per example
            if ($xml && isset($xml->data->success) && (string)$xml->data->success === '1') {
                return [
                    'success' => true,
                    'clientid' => (string)$xml->data->clientid
                ];
            }

            // Fallback to checking status attribute if data->success is not present
            if ($xml && (string)$xml->attributes()->status === 'OK') {
                // If it's add_client, it might have data->clientid even if status is OK
                if (isset($xml->data->clientid)) {
                    return [
                        'success' => true,
                        'clientid' => (string)$xml->data->clientid
                    ];
                }

                // Keep the old items->client->clientid as a second fallback just in case
                if (isset($xml->items->client->clientid)) {
                    return [
                        'success' => true,
                        'clientid' => (string)$xml->items->client->clientid
                    ];
                }

                return ['success' => true];
            }

            return [
                'success' => false,
                'error' => $xml ? 'RMM Error: ' . (string) $xml->attributes()->status : 'Invalid XML response.'
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lists all sites for a specific client ID in N-able RMM.
     *
     * @param string $clientid The RMM internal client ID
     * @return array List of sites or error message
     */
    public function listSites(string $clientid): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Integration not fully configured or active.'];
        }

        try {
            $url = rtrim($this->server, '/') . '/api/';
            $response = Http::get($url, [
                'apikey' => $this->apiKey,
                'service' => 'list_sites',
                'clientid' => $clientid,
            ]);

            if ($response->failed()) {
                return ['error' => 'HTTP Error: ' . $response->status()];
            }

            return $this->parseXmlSites($response->body());
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Parse the XML response from list_sites.
     *
     * @param string $xmlString
     * @return array
     */
    protected function parseXmlSites(string $xmlString): array
    {
        try {
            $xml = simplexml_load_string($xmlString);
            if (!$xml || (string) $xml->attributes()->status !== 'OK') {
                return ['error' => $xml ? (string) $xml->attributes()->status : 'Invalid XML response.'];
            }

            $sites = [];
            foreach ($xml->items->site as $site) {
                $sites[] = [
                    'siteid' => (string) $site->siteid,
                    'name' => (string) $site->name,
                ];
            }

            return $sites;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Add a site to a client in N-able RMM.
     *
     * @param string $clientid
     * @param string $sitename
     * @return array ['success' => bool, 'error' => ?string, 'siteid' => ?string]
     */
    public function addSite(string $clientid, string $sitename): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Integration not fully configured or active.'];
        }

        try {
            $url = rtrim($this->server, '/') . '/api/';
            $response = Http::get($url, [
                'apikey' => $this->apiKey,
                'service' => 'add_site',
                'clientid' => $clientid,
                'sitename' => $sitename,
            ]);

            if ($response->failed()) {
                return ['success' => false, 'error' => 'HTTP Error: ' . $response->status()];
            }

            $xml = simplexml_load_string($response->body());

            // Handle structure from user example: <result><data><success>1</success><siteid>123</siteid></data></result>
            if ($xml && isset($xml->data->success) && (string)$xml->data->success === '1') {
                return [
                    'success' => true,
                    'siteid' => isset($xml->data->siteid) ? (string)$xml->data->siteid : null
                ];
            }

            // Fallback for different RMM API version/response style
            if ($xml && (string) $xml->attributes()->status === 'OK') {
                return [
                    'success' => true,
                    'siteid' => isset($xml->items->site->siteid) ? (string)$xml->items->site->siteid : null
                ];
            }

            return [
                'success' => false,
                'error' => $xml ? 'RMM Error: ' . ($xml->attributes()->status ?? 'Unknown error') : 'Invalid XML response.'
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
