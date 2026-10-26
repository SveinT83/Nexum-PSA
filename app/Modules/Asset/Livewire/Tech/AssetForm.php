<?php

namespace App\Modules\Asset\Livewire\Tech;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Asset\Support\AssetSettings;
use App\Modules\Documentation\Models\Vendor;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * Livewire form for creating and editing assets.
 *
 * The component remains registered with the legacy alias
 * `tech.assets.asset-form`, so Blade templates do not need to change when the
 * class moves into the Asset module.
 *
 * Dependencies:
 * - When a Client is selected, the list of available Sites and Users (Owners)
 *   is filtered to only show those belonging to the selected Client.
 */
class AssetForm extends Component
{
    // =========================================================================
    // SECTION: FORM PROPERTIES
    // =========================================================================

    /** @var int|null The selected client ID */
    public $client_id;

    /** @var int|null The selected site ID */
    public $site_id;

    /** @var int|null The selected user (owner) ID */
    public $user_id;

    /** @var string The asset name */
    public $name;

    /** @var string The asset type (server, pc, laptop, etc.) */
    public $type = 'other';

    /** @var int|null The selected vendor ID from system vendors */
    public $vendor_id;

    /** @var string|null Manual vendor name (fallback) */
    public $vendor;

    /** @var string|null Asset model name */
    public $model;

    /** @var string|null Serial number for identification/RMM matching */
    public $serial_number;

    /** @var string|null Network hostname */
    public $hostname;

    /** @var string|null IP address */
    public $ip_address;

    /** @var string IP configuration type (dhcp or fixed) */
    public $ip_type = 'dhcp';

    /** @var string|null Network MAC address */
    public $mac_address;

    /** @var string|null Business/data sensitivity rating */
    public $sensitivity_level;

    /** @var string|null Operational criticality rating */
    public $criticality_level;

    // =========================================================================
    // SECTION: DATA COLLECTIONS
    // =========================================================================

    public $clients = [];

    public $sites = [];

    public $users = [];

    public $vendors = [];

    public $assetTypes = [];

    /** @var Asset|null The asset model instance being edited */
    public $asset;

    // =========================================================================
    // SECTION: LIFECYCLE METHODS
    // =========================================================================

    /**
     * Component Initialization
     *
     * @param  Asset|null  $asset  Optional asset instance for editing
     * @param  int|null  $client_id  Initial client ID from request
     * @param  int|null  $site_id  Initial site ID from request
     */
    public function mount(?Asset $asset = null, $client_id = null, $site_id = null)
    {
        $this->asset = $asset;
        $settings = app(AssetSettings::class);
        $configuredSettings = $settings->get();
        $this->assetTypes = $settings->typeOptions($asset?->type);

        try {
            // Load base collections for the initial form state. These lists are
            // small reference datasets in the current UI; if they grow large,
            // replace them with searchable async inputs.
            $this->clients = Client::orderBy('name')->get();
            $this->vendors = Vendor::orderBy('name')->get();
        } catch (\Exception $e) {
            Log::error('AssetForm mount error: '.$e->getMessage());
            $this->clients = collect();
            $this->vendors = collect();
        }

        // If we are editing an existing asset, fill the form fields
        if ($this->asset && $this->asset->exists) {
            $this->fill($this->asset->toArray());
            // Trigger dependency update for sites and users.
            $this->updatedClientId($this->client_id);
        } else {
            $this->type = $configuredSettings['default_type'];
            $this->ip_type = $configuredSettings['default_ip_type'];

            // Pre-fill if provided from request context
            if ($client_id) {
                $this->client_id = $client_id;
                $this->updatedClientId($this->client_id);
            }
            if ($site_id) {
                $this->site_id = $site_id;
            }
        }
    }

    // =========================================================================
    // SECTION: EVENT HANDLERS / DYNAMIC LOGIC
    // =========================================================================

    /**
     * Dynamic Dependency Handler: Client Updated
     *
     * Refreshes the site and user collections when the client changes.
     *
     * @param  mixed  $value  The new client_id
     */
    public function updatedClientId($value)
    {
        if ($value) {
            try {
                // Filter sites and users by the selected client so the asset
                // cannot be assigned across client boundaries in the UI.
                $this->sites = ClientSite::where('client_id', $value)->orderBy('name')->get();
                $this->users = ClientUser::whereHas('site', function ($q) use ($value) {
                    $q->where('client_id', $value);
                })->orderBy('name')->get();
            } catch (\Exception $e) {
                Log::error('AssetForm update client error: '.$e->getMessage());
                $this->sites = collect();
                $this->users = collect();
            }
        } else {
            // Reset dependent collections if no client is selected
            $this->sites = [];
            $this->users = [];
            $this->site_id = null;
            $this->user_id = null;
        }
    }

    // =========================================================================
    // SECTION: PERSISTENCE
    // =========================================================================

    /**
     * Save/Update Asset
     *
     * Validates input and persists changes to the database.
     */
    public function save()
    {
        $editing = $this->asset && $this->asset->exists;
        $actor = auth()->user();
        abort_unless($actor && app(\App\Http\Middleware\EnforceTechRoutePermission::class)->allowsActor(
            $actor, $editing ? 'asset.update' : 'asset.create'), 403);
        $payload = [];
        foreach (\App\Modules\Asset\Actions\SaveManualAsset::FIELDS as $field) {
            if ($field !== 'status') {
                $payload[$field] = $this->{$field};
            }
        }
        $request = \Illuminate\Http\Request::create('/', 'POST', $payload);
        $asset = app(\App\Modules\Asset\Actions\SaveManualAsset::class)->handle($request, $editing ? $this->asset : null);
        session()->flash('success', $editing ? 'Asset updated successfully.' : 'Asset created successfully.');

        return redirect()->route('tech.assets.show', $asset->id);
    }

    // =========================================================================
    // SECTION: RENDERING
    // =========================================================================

    /**
     * Render the component view
     */
    public function render()
    {
        return view('asset::Livewire.Tech.asset-form');
    }
}
