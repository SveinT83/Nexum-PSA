<?php

namespace App\Modules\Asset\Livewire\Tech;

use App\Jobs\Integrations\Alerts\SyncNAbleAlertsJob;
use App\Jobs\Integrations\Alerts\SyncTacticalAlertsJob;
use App\Models\System\Integrations\Integration;
use App\Models\Tech\Work\Assets\Asset;
use Livewire\Component;

class AssetAlerts extends Component
{
    public Asset $asset;
    public $isSyncing = false;

    protected $listeners = ['refreshAlerts' => '$refresh'];

    public function mount(Asset $asset)
    {
        $this->asset = $asset;
    }

    public function syncAlerts()
    {
        $this->isSyncing = true;

        // Run both supported RMM alert syncs synchronously from the detail page.
        // Queue connection is sync in tests and can be async in production, but
        // dispatchSync keeps the user's "Sync Alerts" action deterministic.
        $nableIntegration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        $tacticalIntegration = Integration::where('type', 'tactical_rmm')->where('status', 'active')->first();

        if ($nableIntegration) {
            SyncNAbleAlertsJob::dispatchSync($nableIntegration->id, $this->asset->id);
        }

        if ($tacticalIntegration) {
            SyncTacticalAlertsJob::dispatchSync($tacticalIntegration->id, $this->asset->id);
        }

        $this->asset->load('alerts');
        $this->isSyncing = false;

        $this->dispatch('refreshAlerts');
        session()->flash('alert_sync_success', 'Alerts updated successfully.');
    }

    public function render()
    {
        $activeAlerts = $this->asset->alerts()->where('status', 'active')->latest('last_seen_at')->get();
        $resolvedAlerts = $this->asset->alerts()->where('status', 'resolved')->latest('resolved_at')->take(5)->get();

        return view('asset::Livewire.Tech.Work.asset-alerts', [
            'activeAlerts' => $activeAlerts,
            'resolvedAlerts' => $resolvedAlerts,
        ]);
    }
}
