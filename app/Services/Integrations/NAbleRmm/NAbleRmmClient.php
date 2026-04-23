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

            $status = (string) ($xml->attributes()->status ?? $xml->status ?? '');

            // Fallback for missing status
            if (empty($status) && (isset($xml->items) || isset($xml->item) || isset($xml->client))) {
                $status = 'OK';
            }

            if ($status !== 'OK' && $status !== 'SUCCESS') {
                Log::error('N-able RMM API error status in XML: ' . $status, ['xml' => $xmlString]);
                return ['error' => 'API returned status: ' . ($status ?: 'Unknown status')];
            }

            $clients = [];

            // Handle nested structure items -> client
            if (isset($xml->items->client)) {
                foreach ($xml->items->client as $client) {
                    $clients[] = [
                        'name' => (string) $client->name,
                        'clientid' => (string) ($client->clientid ?? $client->id ?? ''),
                    ];
                }
            }
            // Handle flat structure client
            elseif (isset($xml->client)) {
                foreach ($xml->client as $client) {
                    $clients[] = [
                        'name' => (string) $client->name,
                        'clientid' => (string) ($client->clientid ?? $client->id ?? ''),
                    ];
                }
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
                    'clientid' => (string)($xml->data->clientid ?? $xml->items->client->clientid ?? '')
                ];
            }

            // Fallback to checking status attribute if data->success is not present
            $status = $xml ? (string)($xml->attributes()->status ?? $xml->status ?? '') : null;

            if ($status === 'OK' || $status === 'SUCCESS') {
                $clientid = (string)($xml->data->clientid ?? $xml->items->client->clientid ?? $xml->items->client->id ?? $xml->clientid ?? '');

                return [
                    'success' => true,
                    'clientid' => $clientid ?: null
                ];
            }

            $errorMsg = $xml ? (string)($xml->attributes()->status ?? $xml->status ?? $xml->error ?? '') : 'Invalid XML response.';
            if (empty($errorMsg) && $xml) {
                $errorMsg = 'Unknown RMM Error (No status provided)';
            }

            Log::error('N-able RMM addClient failed', [
                'name' => $name,
                'status' => $status,
                'response' => $response->body()
            ]);

            return [
                'success' => false,
                'error' => 'RMM Error: ' . $errorMsg
            ];
        } catch (\Exception $e) {
            Log::error('N-able RMM addClient exception', [
                'name' => $name,
                'message' => $e->getMessage()
            ]);
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
     * Lists all devices (servers or workstations) for a specific client ID in N-able RMM.
     *
     * @param string $clientid The RMM internal client ID
     * @param string $deviceType 'server' or 'workstation'
     * @return array List of devices or error message
     */
    public function listDevices(string $clientid, string $deviceType = 'server'): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Integration not fully configured or active.'];
        }

        // Map internal device types to N-able RMM types
        // Internal: server, workstation, mobile
        // N-able RMM: server, workstation, mobile_device
        $rmmType = $deviceType;
        if ($deviceType === 'mobile') {
            $rmmType = 'mobile_device';
        }

        try {
            $url = rtrim($this->server, '/') . '/api/';
            $response = Http::get($url, [
                'apikey' => $this->apiKey,
                'service' => 'list_devices_at_client',
                'clientid' => $clientid,
                'devicetype' => $rmmType,
            ]);

            if ($response->failed()) {
                return ['error' => 'HTTP Error: ' . $response->status()];
            }

            return $this->parseXmlDevices($response->body());
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Parse the XML response from list_devices_at_client.
     * The structure can be nested: items -> client -> site -> (server|workstation)
     *
     * @param string $xmlString
     * @return array
     */
    protected function parseXmlDevices(string $xmlString): array
    {
        try {
            $xml = simplexml_load_string($xmlString);
            if (!$xml) {
                return ['error' => 'Invalid XML response.'];
            }

            // Check status in attributes or direct child
            $status = (string) ($xml->attributes()->status ?? $xml->status ?? '');

            // Fallback: If status is empty but we have <items>, <item>, or <client>, treat as OK
            if (empty($status) && (isset($xml->items) || isset($xml->item) || isset($xml->client))) {
                $status = 'OK';
            }

            // Log for debugging if status is not OK
            if ($status !== 'OK' && $status !== 'SUCCESS') {
                \Illuminate\Support\Facades\Log::warning("N-able RMM API returned non-OK status: $status", [
                    'xml' => $xmlString
                ]);
            }

            if ($status !== 'OK' && $status !== 'SUCCESS') {
                return ['error' => 'API Error: ' . ($status ?: 'Unknown status')];
            }

            $devices = [];

            // Check for servers
            if (isset($xml->items->client->site->server)) {
                foreach ($xml->items->client->site->server as $device) {
                    $siteId = (string) ($xml->items->client->site->id ?? $xml->items->client->site->siteid ?? '');
                    $devices[] = $this->mapDeviceData($device, $siteId, 'server');
                }
            }

            // Check for workstations
            if (isset($xml->items->client->site->workstation)) {
                foreach ($xml->items->client->site->workstation as $device) {
                    $siteId = (string) ($xml->items->client->site->id ?? $xml->items->client->site->siteid ?? '');
                    $devices[] = $this->mapDeviceData($device, $siteId, 'workstation');
                }
            }

            // Check for workstation nodes (alternative tag)
            if (isset($xml->items->client->site->workstation_node)) {
                foreach ($xml->items->client->site->workstation_node as $device) {
                    $siteId = (string) ($xml->items->client->site->id ?? $xml->items->client->site->siteid ?? '');
                    $devices[] = $this->mapDeviceData($device, $siteId, 'workstation');
                }
            }

            // Check for mobile devices
            if (isset($xml->items->client->site->mobile_device)) {
                foreach ($xml->items->client->site->mobile_device as $device) {
                    $siteId = (string) ($xml->items->client->site->id ?? $xml->items->client->site->siteid ?? '');
                    $devices[] = $this->mapDeviceData($device, $siteId, 'mobile_device');
                }
            }

            // Case 1: Nested structure (items -> client -> site -> deviceType)
            if (isset($xml->items->client)) {
                foreach ($xml->items->client as $client) {
                    if (isset($client->site)) {
                        foreach ($client->site as $site) {
                            $siteId = (string) ($site->id ?? $site->siteid ?? $site->site_id);

                            // Check for servers
                            if (isset($site->server)) {
                                foreach ($site->server as $device) {
                                    $devices[] = $this->mapDeviceData($device, $siteId, 'server');
                                }
                            }

                            // Check for workstations
                            if (isset($site->workstation)) {
                                foreach ($site->workstation as $device) {
                                    $devices[] = $this->mapDeviceData($device, $siteId, 'workstation');
                                }
                            }

                            // Check for workstation nodes (alternative tag)
                            if (isset($site->workstation_node)) {
                                foreach ($site->workstation_node as $device) {
                                    $devices[] = $this->mapDeviceData($device, $siteId, 'workstation');
                                }
                            }

                            // Check for mobile devices
                            if (isset($site->mobile_device)) {
                                foreach ($site->mobile_device as $device) {
                                    $devices[] = $this->mapDeviceData($device, $siteId, 'mobile_device');
                                }
                            }
                        }
                    }
                }
            }
            // Case 2: Direct device list (items -> device)
            elseif (isset($xml->items->device)) {
                foreach ($xml->items->device as $device) {
                    $devices[] = $this->mapDeviceData($device, (string) ($device->siteid ?? $device->site_id ?? ''), 'unknown');
                }
            }

            // Deduplicate devices based on their ID
            $uniqueDevices = [];
            foreach ($devices as $device) {
                if (isset($device['deviceid'])) {
                    $uniqueDevices[$device['deviceid']] = $device;
                } else {
                    $uniqueDevices[] = $device;
                }
            }

            return array_values($uniqueDevices);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Maps a device XML node to a standard array format.
     *
     * @param \SimpleXMLElement $device
     * @param string $siteId
     * @param string $type
     * @return array
     */
    protected function mapDeviceData($device, $siteId, $type): array
    {
        $mappedType = 'other';
        if ($type === 'server') {
            $mappedType = 'server';
        } elseif (in_array($type, ['workstation', 'workstation_node', 'pc'])) {
            $mappedType = 'pc';
        } elseif ($type === 'laptop') {
            $mappedType = 'laptop';
        } elseif ($type === 'mobile_device') {
            $mappedType = 'mobile';
        }

        return [
            'deviceid' => (string) ($device->id ?? $device->deviceid ?? $device->device_id ?? $device->id_device ?? ''),
            'name' => (string) ($device->name ?? $device->hostname ?? $device->display_name ?? $device->description ?? ''),
            'siteid' => $siteId,
            'os' => (string) ($device->os ?? $device->operating_system ?? $device->os_name ?? $device->platform ?? ''),
            'ip_address' => (string) ($device->ip_address ?? $device->ipaddress ?? $device->ip ?? $device->lan_ip ?? ''),
            'mac_address' => (string) ($device->mac_address ?? $device->macaddress ?? $device->mac ?? $device->ethernet_address ?? ''),
            'serial_number' => (string) ($device->serial_number ?? $device->serialnumber ?? $device->serial_no ?? $device->system_serial ?? ''),
            'vendor' => (string) ($device->vendor ?? $device->manufacturer ?? $device->system_manufacturer ?? ''),
            'model' => (string) ($device->model ?? $device->product ?? $device->system_product ?? ''),
            'type' => $mappedType
        ];
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
            if (!$xml) {
                return ['error' => 'Invalid XML response.'];
            }

            $status = (string) ($xml->attributes()->status ?? $xml->status ?? '');

            // Fallback for missing status
            if (empty($status) && (isset($xml->items) || isset($xml->item) || isset($xml->site))) {
                $status = 'OK';
            }

            if ($status !== 'OK' && $status !== 'SUCCESS') {
                return ['error' => 'API Error: ' . ($status ?: 'Unknown status')];
            }

            $sites = [];

            // Handle nested structure items -> site
            if (isset($xml->items->site)) {
                foreach ($xml->items->site as $site) {
                    $sites[] = [
                        'siteid' => (string) ($site->siteid ?? $site->id ?? ''),
                        'name' => (string) $site->name,
                    ];
                }
            }
            // Handle flat structure site
            elseif (isset($xml->site)) {
                foreach ($xml->site as $site) {
                    $sites[] = [
                        'siteid' => (string) ($site->siteid ?? $site->id ?? ''),
                        'name' => (string) $site->name,
                    ];
                }
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
                    'siteid' => (string)($xml->data->siteid ?? $xml->items->site->siteid ?? '')
                ];
            }

            // Fallback for different RMM API version/response style
            $status = $xml ? (string)($xml->attributes()->status ?? $xml->status ?? '') : null;

            if ($status === 'OK' || $status === 'SUCCESS') {
                $siteid = (string)($xml->data->siteid ?? $xml->items->site->siteid ?? $xml->items->site->id ?? $xml->siteid ?? '');
                return [
                    'success' => true,
                    'siteid' => $siteid ?: null
                ];
            }

            $errorMsg = $xml ? (string)($xml->attributes()->status ?? $xml->status ?? $xml->error ?? '') : 'Invalid XML response.';
            if (empty($errorMsg) && $xml) {
                $errorMsg = 'Unknown RMM Error (No status provided)';
            }

            Log::error('N-able RMM addSite failed', [
                'clientid' => $clientid,
                'sitename' => $sitename,
                'status' => $status,
                'response' => $response->body()
            ]);

            return [
                'success' => false,
                'error' => 'RMM Error: ' . $errorMsg
            ];
        } catch (\Exception $e) {
            Log::error('N-able RMM addSite exception', [
                'clientid' => $clientid,
                'sitename' => $sitename,
                'message' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
