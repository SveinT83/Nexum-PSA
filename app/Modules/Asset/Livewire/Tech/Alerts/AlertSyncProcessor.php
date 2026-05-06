<?php

namespace App\Modules\Asset\Livewire\Tech\Alerts;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Models\Tech\Work\Assets\Asset;
use App\Jobs\Integrations\Alerts\SyncNAbleAlertsJob;
use App\Jobs\Integrations\Alerts\SyncTacticalAlertsJob;
use Livewire\Component;

class AlertSyncProcessor extends Component
{
    protected $listeners = [
        'syncAllClientAlerts' => 'syncAllClientAlerts',
        'syncAllAlerts' => 'syncAllAlerts'
    ];

    public function syncAllAlerts()
    {
        // This component is mounted once in the tech layout and acts as a small
        // event bridge for "sync all alerts" actions triggered from asset views.
        $nableIntegration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        $tacticalIntegration = Integration::where('type', 'tactical_rmm')->where('status', 'active')->first();

        $assets = Asset::all();
        $totalAssets = $assets->count();

        if ($totalAssets === 0) {
            $this->dispatch('notify', ['type' => 'info', 'message' => 'No assets found in the system.']);
            return;
        }

        foreach ($assets as $asset) {
            if ($nableIntegration) {
                SyncNAbleAlertsJob::dispatchSync($nableIntegration->id, $asset->id);
            }
            if ($tacticalIntegration) {
                SyncTacticalAlertsJob::dispatchSync($tacticalIntegration->id, $asset->id);
            }
        }

        $this->dispatch('notify', ['type' => 'success', 'message' => "Alert sync completed for all {$totalAssets} assets."]);
        $this->dispatch('refreshAlerts');
    }

    public function syncAllClientAlerts($params)
    {
        // Client-scoped sync is triggered by summary widgets. Missing client IDs
        // are ignored because the event can also be fired from global contexts.
        $clientId = $params['client_id'] ?? null;
        if (!$clientId) return;

        $client = Client::find($clientId);
        if (!$client) return;

        $nableIntegration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        $tacticalIntegration = Integration::where('type', 'tactical_rmm')->where('status', 'active')->first();

        $assets = $client->assets;
        $totalAssets = $assets->count();

        if ($totalAssets === 0) {
            $this->dispatch('notify', ['type' => 'info', 'message' => 'No assets found for this client.']);
            return;
        }

        foreach ($assets as $asset) {
            if ($nableIntegration) {
                SyncNAbleAlertsJob::dispatchSync($nableIntegration->id, $asset->id);
            }
            if ($tacticalIntegration) {
                SyncTacticalAlertsJob::dispatchSync($tacticalIntegration->id, $asset->id);
            }
        }

        $this->dispatch('notify', ['type' => 'success', 'message' => "Alert sync completed for {$totalAssets} assets."]);
        $this->dispatch('refreshAlerts');
    }

    public function render()
    {
        return '<div></div>';
    }
}
