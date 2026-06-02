<?php
namespace App\Modules\Integration\Livewire\Tech\Admin\System\Integrations;

use App\Models\System\Integrations\ClientRmmLink;
use App\Models\System\Integrations\Integration;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Livewire\Component;

class NAbleRmmSync extends Component
{
    /**
     * @var bool Whether the synchronization modal is visible
     */
    public $showModal = false;

    /**
     * @var string Type of synchronization: 'clients_from', 'clients_to', 'sites_from', 'sites_to', 'assets_from'
     */
    public $syncType = '';

    /**
     * Label mapping for the synchronization modal.
     */
    protected $syncLabels = [
        'clients_from' => 'Sync Clients from RMM',
        'clients_to' => 'Sync Clients to RMM',
        'sites_from' => 'Sync Sites from RMM',
        'sites_to' => 'Sync Sites to RMM',
        'assets_from' => 'Sync Assets from RMM',
    ];

    /**
     * @var int Progress percentage (0-100)
     */
    public $progress = 0;

    /**
     * @var int Total number of items to process
     */
    public $total = 0;

    /**
     * @var int Current item index being processed
     */
    public $current = 0;

    /**
     * @var int Number of items successfully created or synchronized
     */
    public $successCount = 0;

    /**
     * @var int Number of items updated (e.g., name changes)
     */
    public $updatedCount = 0;

    /**
     * @var int Number of items existing locally but newly linked via ID
     */
    public $linkedCount = 0;

    /**
     * @var int Number of items that failed processing
     */
    public $errorCount = 0;

    /**
     * @var array List of error messages encountered during synchronization
     */
    public $errors = [];

    /**
     * @var bool Whether the entire process has finished
     */
    public $isComplete = false;

    /**
     * @var bool Whether a batch is currently being processed
     */
    public $isProcessing = false;

    /**
     * @var array The collection of items (Clients or Sites) queued for processing
     */
    public $itemsToProcess = [];

    /**
     * @var int|null Targeted site ID for filtered synchronization
     */
    public $targetSiteId = null;

    /**
     * Livewire listeners for external event triggers
     */
    protected $listeners = ['startSync' => 'initSync', 'startTargetedSync' => 'initTargetedSync'];

    /**
     * Initializes a targeted synchronization process for a specific client or site.
     *
     * @param array $params Targeted parameters ['type' => string, 'client_id' => int, 'site_id' => int|null]
     */
    public function initTargetedSync($params)
    {
        $type = $params['type'] ?? 'assets_from';
        $clientId = $params['client_id'] ?? null;
        $siteId = $params['site_id'] ?? null;

        if (!$clientId) {
            $this->errors[] = 'Client ID is required for targeted sync.';
            $this->errorCount++;
            $this->isComplete = true;
            $this->showModal = true;
            return;
        }

        $this->syncType = $type;
        $this->resetProgress();
        $this->targetSiteId = $siteId;
        $this->showModal = true;

        $integration = Integration::where('type', 'rmm')->first();
        if (!$integration || !$integration->getSecret('api_key')) {
            $this->errors[] = 'N-able RMM API key not found.';
            $this->errorCount++;
            $this->isComplete = true;
            return;
        }

        if ($this->syncType === 'assets_from') {
            $client = Client::where('id', $clientId)
                ->whereHas('rmmLinks', function($query) use ($integration) {
                    $query->where('integration_id', $integration->id);
                })
                ->where('active', true)
                ->first();

            if (!$client) {
                $this->errors[] = 'Client not found or not linked to RMM.';
                $this->errorCount++;
                $this->isComplete = true;
                return;
            }
            $this->itemsToProcess = [$client->toArray()];
        }

        $this->total = count($this->itemsToProcess);
        if ($this->total === 0) {
            $this->isComplete = true;
            $this->errors[] = 'No items found for synchronization.';
            $this->errorCount++;
        } else {
            $this->isProcessing = true;
            $this->dispatch('triggerBatch');
        }
    }

    /**
     * Initializes the synchronization process based on the requested type.
     * Fetches the necessary data from RMM or local database to prepare the processing queue.
     *
     * @param string $type The sync direction/target
     */
    public function initSync($params)
    {
        if (is_string($params)) {
            $type = $params;
        } elseif (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        $this->syncType = $type;
        $this->resetProgress();
        $this->showModal = true;

        $integration = Integration::where('type', 'rmm')->first();
        if (!$integration || !$integration->getSecret('api_key')) {
            $this->errors[] = 'N-able RMM API key not found.';
            $this->errorCount++;
            $this->isComplete = true;
            return;
        }

        $rmmClient = new NAbleRmmClient($integration);

        if ($this->syncType === 'clients_from') {
            $rmmClients = $rmmClient->listClients();
            if (isset($rmmClients['error'])) {
                $this->errors[] = 'Failed to fetch clients: ' . $rmmClients['error'];
                $this->errorCount++;
                $this->isComplete = true;
                return;
            }
            $this->itemsToProcess = $rmmClients;
        } elseif ($this->syncType === 'clients_to') {
            $this->itemsToProcess = Client::leftJoin('client_rmm_links', function($join) use ($integration) {
                $join->on('clients.id', '=', 'client_rmm_links.linkable_id')
                    ->where('client_rmm_links.linkable_type', '=', Client::class)
                    ->where('client_rmm_links.integration_id', '=', $integration->id);
            })
            ->whereNull('client_rmm_links.id')
            ->where('clients.active', true)
            ->select('clients.*')
            ->get()
            ->toArray();
        } elseif ($this->syncType === 'sites_from') {
            $this->itemsToProcess = Client::whereHas('rmmLinks', function($query) use ($integration) {
                $query->where('integration_id', $integration->id);
            })->where('active', true)->get()->toArray();
        } elseif ($this->syncType === 'sites_to') {
            $this->itemsToProcess = Client::whereHas('rmmLinks', function($query) use ($integration) {
                $query->where('integration_id', $integration->id);
            })->where('active', true)->get()->toArray();
        } elseif ($this->syncType === 'assets_from') {
            $this->itemsToProcess = Client::whereHas('rmmLinks', function($query) use ($integration) {
                $query->where('integration_id', $integration->id);
            })->where('active', true)->get()->toArray();
        }

        $this->total = count($this->itemsToProcess);
        if ($this->total === 0) {
            $this->isComplete = true;
        } else {
            $this->isProcessing = true;
            // Kick off the first batch
            $this->dispatch('triggerBatch');
        }
    }

    /**
     * Processes the next batch of items from the queue.
     * Uses batching to prevent PHP timeout and keep the UI responsive.
     * Dispatches a browser event to trigger the next batch after a short delay.
     */
    public function processNextBatch()
    {
        if (!$this->isProcessing || $this->current >= $this->total) {
            $this->isProcessing = false;
            $this->isComplete = true;
            $this->updateLastSync();
            return;
        }

        $batchSize = 5; // Process 5 at a time to keep it snappy
        $batch = array_slice($this->itemsToProcess, $this->current, $batchSize);
        $integration = Integration::where('type', 'rmm')->first();
        $rmmClient = new NAbleRmmClient($integration);

        foreach ($batch as $item) {
            try {
                if ($this->syncType === 'clients_from') {
                    $this->syncClientFrom($item);
                } elseif ($this->syncType === 'clients_to') {
                    $this->syncClientTo($item, $rmmClient);
                } elseif ($this->syncType === 'sites_from') {
                    $this->syncSitesFrom($item, $rmmClient);
                } elseif ($this->syncType === 'sites_to') {
                    $this->syncSitesTo($item, $rmmClient);
                } elseif ($this->syncType === 'assets_from') {
                    $this->syncAssetsFrom($item, $rmmClient);
                }
            } catch (\Exception $e) {
                $this->errorCount++;
                $this->errors[] = "Error processing " . ($item['name'] ?? 'item') . ": " . $e->getMessage();
            }
            $this->current++;
        }

        $this->progress = $this->total > 0 ? round(($this->current / $this->total) * 100) : 100;

        if ($this->current < $this->total) {
            $this->dispatch('triggerBatch');
        } else {
            $this->isProcessing = false;
            $this->isComplete = true;
            $this->updateLastSync();
        }
    }

    /**
     * Synchronizes a single client from N-able RMM to the local database.
     * Handles matching by RMM ID or name, updates existing records, or creates new ones.
     *
     * @param array $rmmClient Client data from RMM API
     */
    protected function syncClientFrom($rmmClient)
    {
        $integration = Integration::where('type', 'rmm')->first();

        // Find by RMM Link first
        $link = ClientRmmLink::where('integration_id', $integration->id)
            ->where('external_id', $rmmClient['clientid'])
            ->where('linkable_type', Client::class)
            ->first();

        $localClient = null;
        if ($link) {
            $localClient = $link->linkable;
        } else {
            // Try finding by name to link existing
            $localClient = Client::where('name', $rmmClient['name'])->first();
        }

        if ($localClient) {
            $hasChanges = false;

            // Check if link exists, if not create it
            if (!$link) {
                ClientRmmLink::create([
                    'integration_id' => $integration->id,
                    'external_id' => $rmmClient['clientid'],
                    'linkable_type' => Client::class,
                    'linkable_id' => $localClient->id,
                ]);

                $this->linkedCount++;
            }

            if ($localClient->name !== $rmmClient['name']) {
                $localClient->name = $rmmClient['name'];
                $hasChanges = true;
                if ($localClient->wasRecentlyCreated === false) {
                    $this->updatedCount++;
                }
            }

            if ($hasChanges) {
                $localClient->save();
            }
        } else {
            $suggestedClientNumber = $this->generateClientNumber();

            $newClient = Client::create([
                'name' => $rmmClient['name'],
                'client_number' => $suggestedClientNumber,
                'active' => true,
            ]);

            ClientRmmLink::create([
                'integration_id' => $integration->id,
                'external_id' => $rmmClient['clientid'],
                'linkable_type' => Client::class,
                'linkable_id' => $newClient->id,
            ]);

            ClientSite::create([
                'client_id' => $newClient->id,
                'name' => 'Default',
                'is_default' => true,
            ]);

            $this->successCount++;
        }
    }

    /**
     * Synchronizes a single local client to N-able RMM.
     * Creates the client in RMM and stores the returned RMM ID locally.
     *
     * @param array $item Local client data (array)
     * @param NAbleRmmClient $client RMM API Service instance
     */
    protected function syncClientTo($item, $client)
    {
        $integration = Integration::where('type', 'rmm')->first();
        $localClient = Client::find($item['id']);
        if (!$localClient) return;

        $result = $client->addClient($localClient->name);
        if ($result['success']) {
            // Create or update RMM Link
            ClientRmmLink::updateOrCreate(
                [
                    'integration_id' => $integration->id,
                    'external_id' => $result['clientid'],
                    'linkable_type' => Client::class,
                ],
                [
                    'linkable_id' => $localClient->id,
                ]
            );

            $this->successCount++;
        } else {
            $this->errorCount++;
            $this->errors[] = "RMM API Error for " . $localClient->name . ": " . ($result['error'] ?? 'Unknown error');
        }
    }

    /**
     * Synchronizes sites from N-able RMM to a specific local client.
     * Matches by RMM ID or name and ensures all RMM sites exist locally.
     *
     * @param array $item Local client data (parent of the sites)
     * @param NAbleRmmClient $client RMM API Service instance
     */
    protected function syncSitesFrom($item, $client)
    {
        $integration = Integration::where('type', 'rmm')->first();
        $localClient = Client::find($item['id']);
        if (!$localClient) return;

        $clientLink = $localClient->rmmLinks()
            ->where('integration_id', $integration->id)
            ->first();

        if (!$clientLink) {
            $this->errorCount++;
            $this->errors[] = "Client " . $localClient->name . " is not linked to RMM.";
            return;
        }

        $rmmSites = $client->listSites($clientLink->external_id);
        if (isset($rmmSites['error'])) {
            $this->errorCount++;
            $this->errors[] = "Failed to fetch sites for " . $localClient->name . ": " . $rmmSites['error'];
            return;
        }

        foreach ($rmmSites as $rmmSite) {
            // Find by RMM Link first
            $link = ClientRmmLink::where('integration_id', $integration->id)
                ->where('external_id', $rmmSite['siteid'])
                ->where('linkable_type', ClientSite::class)
                ->first();

            $localSite = null;
            if ($link) {
                $localSite = $link->linkable;
            } else {
                // Try finding by name to link existing
                $localSite = ClientSite::where('client_id', $localClient->id)
                    ->where('name', $rmmSite['name'])
                    ->first();
            }

            if ($localSite) {
                $hasChanges = false;

                // Check if link exists, if not create it
                if (!$link) {
                    ClientRmmLink::create([
                        'integration_id' => $integration->id,
                        'external_id' => $rmmSite['siteid'],
                        'linkable_type' => ClientSite::class,
                        'linkable_id' => $localSite->id,
                    ]);

                    $this->linkedCount++;
                }

                if ($localSite->name !== $rmmSite['name']) {
                    $localSite->name = $rmmSite['name'];
                    $hasChanges = true;
                    $this->updatedCount++;
                }

                if ($hasChanges) $localSite->save();
            } else {
                $newSite = ClientSite::create([
                    'client_id' => $localClient->id,
                    'name' => $rmmSite['name'],
                ]);

                ClientRmmLink::create([
                    'integration_id' => $integration->id,
                    'external_id' => $rmmSite['siteid'],
                    'linkable_type' => ClientSite::class,
                    'linkable_id' => $newSite->id,
                ]);

                $this->successCount++;
            }
        }
    }

    /**
     * Synchronizes local sites to N-able RMM for a specific linked client.
     * Only pushes sites that are not yet linked to RMM.
     *
     * @param array $item Local client data (parent of the sites)
     * @param NAbleRmmClient $client RMM API Service instance
     */
    protected function syncSitesTo($item, $client)
    {
        $integration = Integration::where('type', 'rmm')->first();
        $localClient = Client::find($item['id']);
        if (!$localClient) return;

        $localSites = ClientSite::where('client_id', $localClient->id)
            ->whereDoesntHave('rmmLinks', function($query) use ($integration) {
                $query->where('integration_id', $integration->id);
            })
            ->get();

        foreach ($localSites as $localSite) {
            // Get RMM ID for client
            $clientLink = ClientRmmLink::where('integration_id', $integration->id)
                ->where('linkable_type', Client::class)
                ->where('linkable_id', $localClient->id)
                ->first();

            $clientRmmId = $clientLink ? $clientLink->external_id : null;

            if (!$clientRmmId) {
                $this->errors[] = "Client " . $localClient->name . " is not linked to RMM.";
                $this->errorCount++;
                continue;
            }

            $result = $client->addSite($clientRmmId, $localSite->name);
            if ($result['success']) {
                // Create Link
                ClientRmmLink::updateOrCreate(
                    [
                        'integration_id' => $integration->id,
                        'external_id' => $result['siteid'],
                        'linkable_type' => ClientSite::class,
                    ],
                    [
                        'linkable_id' => $localSite->id,
                    ]
                );

                $this->successCount++;
            } else {
                $this->errorCount++;
                $this->errors[] = "Error adding site " . $localSite->name . " to RMM: " . ($result['error'] ?? 'Unknown error');
            }
        }
    }

    /**
     * Synchronizes assets from N-able RMM to a specific local client.
     * Fetches both servers and workstations for the client.
     *
     * @param array $item Local client data (parent of the assets)
     * @param NAbleRmmClient $client RMM API Service instance
     */
    protected function syncAssetsFrom($item, $client)
    {
        $integration = Integration::where('type', 'rmm')->first();
        $localClient = Client::find($item['id']);
        if (!$localClient) return;

        // If we have a targeted site ID, we need to fetch its RMM ID to filter the RMM results
        $targetRmmSiteId = null;
        if ($this->targetSiteId) {
            $targetSite = ClientSite::where('id', $this->targetSiteId)
                ->where('client_id', $localClient->id)
                ->first();

            if ($targetSite) {
                $link = ClientRmmLink::where('integration_id', $integration->id)
                    ->where('linkable_type', ClientSite::class)
                    ->where('linkable_id', $targetSite->id)
                    ->first();

                $targetRmmSiteId = $link ? (string)$link->external_id : null;
            }

            // If the targeted site doesn't have an RMM ID, we can't sync it
            if (!$targetRmmSiteId) {
                return;
            }
        }

        $deviceTypes = ['server', 'workstation'];

        // Get Client RMM ID
        $clientLink = $localClient->rmmLinks()
            ->where('integration_id', $integration->id)
            ->first();

        if (!$clientLink) {
            $this->errorCount++;
            $this->errors[] = "Client " . $localClient->name . " is not linked to RMM.";
            return;
        }

        foreach ($deviceTypes as $type) {
            $rmmDevices = $client->listDevices($clientLink->external_id, $type);

            if (isset($rmmDevices['error'])) {
                // Ignore errors for mobile devices if not supported by the RMM instance
                if ($type === 'mobile') {
                    continue;
                }

                // If it's empty response but status OK, it's not really an error
                if (str_contains($rmmDevices['error'], 'No devices found') || $rmmDevices['error'] === 'OK') {
                    continue;
                }

                $this->errorCount++;
                $this->errors[] = "Failed to fetch $type assets for " . $localClient->name . ": " . $rmmDevices['error'];
                continue;
            }

            foreach ($rmmDevices as $rmmDevice) {
                // Skip entries that are actually error messages or not devices
                if (isset($rmmDevice['error']) || !isset($rmmDevice['deviceid']) || empty($rmmDevice['deviceid'])) {
                    continue;
                }

                // Skip if site ID is missing in RMM data
                if (empty($rmmDevice['siteid'])) {
                    continue;
                }

                // If we are targeting a specific site, skip devices that don't match
                if ($targetRmmSiteId && (string)$rmmDevice['siteid'] !== $targetRmmSiteId) {
                    continue;
                }

                // Try to find corresponding local site
                // Check Links first
                $localSite = $this->resolveLocalSiteForDevice($integration, $localClient, $rmmDevice);

                if (!$localSite) {
                    continue;
                }

                $asset = \App\Models\Tech\Work\Assets\Asset::whereHas('rmmLinks', function($query) use ($integration, $rmmDevice) {
                    $query->where('integration_id', $integration->id)
                        ->where('external_id', (string)$rmmDevice['deviceid']);
                })->first();

                if (!$asset) {
                    // Fallback to match by name/serial if no link exists
                    $asset = \App\Models\Tech\Work\Assets\Asset::where('client_id', $localClient->id)
                        ->where(function($query) use ($rmmDevice) {
                            if (!empty($rmmDevice['name'])) {
                                $query->where('hostname', $rmmDevice['name']);
                            }
                            if (!empty($rmmDevice['serial_number'])) {
                                $query->orWhere('serial_number', $rmmDevice['serial_number']);
                            }
                        })->first();
                }

                $assetData = [
                    'client_id' => $localClient->id,
                    'site_id' => $localSite->id,
                    'name' => $rmmDevice['name'],
                    'type' => $rmmDevice['type'],
                    'vendor' => $rmmDevice['vendor'] ?: null,
                    'model' => $rmmDevice['model'] ?: null,
                    'serial_number' => $rmmDevice['serial_number'] ?: null,
                    'mac_address' => $rmmDevice['mac_address'] ?: null,
                    'ip_address' => $rmmDevice['ip_address'] ?: null,
                    'hostname' => $rmmDevice['name'],
                    'source' => 'nable',
                    'is_managed' => true,
                    'status' => 'unknown',
                    'last_seen_at' => now(),
                    'metadata' => [
                        'os' => $rmmDevice['os'],
                        'rmm_type' => $type
                    ]
                ];

                if ($asset) {
                    $asset->update($assetData);
                    $this->updatedCount++;
                } else {
                    $asset = \App\Models\Tech\Work\Assets\Asset::create($assetData);
                    $this->successCount++;
                }

                // Ensure RMM link exists
                ClientRmmLink::updateOrCreate(
                    [
                        'integration_id' => $integration->id,
                        'linkable_type' => \App\Models\Tech\Work\Assets\Asset::class,
                        'linkable_id' => $asset->id,
                    ],
                    [
                        'external_id' => (string)$rmmDevice['deviceid'],
                    ]
                );
            }
        }
    }

    /**
     * Resolve the local Site for a device. When the Site has not been imported
     * yet, create a placeholder so asset import can still complete.
     */
    protected function resolveLocalSiteForDevice(Integration $integration, Client $localClient, array $rmmDevice): ?ClientSite
    {
        $rmmSiteId = (string)($rmmDevice['siteid'] ?? '');

        if ($rmmSiteId === '') {
            return null;
        }

        $siteLink = ClientRmmLink::where('integration_id', $integration->id)
            ->where('linkable_type', ClientSite::class)
            ->where('external_id', $rmmSiteId)
            ->first();

        if ($siteLink && $siteLink->linkable instanceof ClientSite) {
            return $siteLink->linkable;
        }

        $localSite = ClientSite::firstOrCreate(
            [
                'client_id' => $localClient->id,
                'name' => 'RMM Site '.$rmmSiteId,
            ],
            [
                'is_default' => ! ClientSite::where('client_id', $localClient->id)->exists(),
            ],
        );

        ClientRmmLink::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'external_id' => $rmmSiteId,
                'linkable_type' => ClientSite::class,
            ],
            [
                'linkable_id' => $localSite->id,
                'metadata' => [
                    'source' => 'asset_sync_placeholder',
                ],
            ],
        );

        return $localSite;
    }

    /**
     * Generates a unique 5-digit client number.
     * Attempts to find the next available number in the sequence.
     *
     * @return string|null The generated number, or null if generation fails after max attempts
     */
    protected function generateClientNumber()
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

    /**
     * Updates the last_sync_at timestamp for the RMM integration record.
     */
    protected function updateLastSync()
    {
        $integration = Integration::where('type', 'rmm')->first();
        if ($integration) {
            $integration->last_sync_at = now();
            $integration->save();
        }
    }

    /**
     * Resets all progress counters and state variables before a new sync run.
     */
    public function resetProgress()
    {
        $this->progress = 0;
        $this->total = 0;
        $this->current = 0;
        $this->successCount = 0;
        $this->updatedCount = 0;
        $this->linkedCount = 0;
        $this->errorCount = 0;
        $this->errors = [];
        $this->isComplete = false;
        $this->isProcessing = false;
        $this->itemsToProcess = [];
        $this->targetSiteId = null;
    }

    /**
     * Closes the sync modal.
     */
    public function closeModal()
    {
        $this->showModal = false;
        if ($this->isComplete) {
            $this->resetProgress();
            return redirect(request()->header('Referer'));
        }
    }

    public function render()
    {
        return view('integration::Livewire.Tech.Admin.System.Integrations.n-able-rmm-sync');
    }
}
