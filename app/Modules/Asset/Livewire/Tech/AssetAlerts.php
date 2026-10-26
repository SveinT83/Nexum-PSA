<?php

namespace App\Modules\Asset\Livewire\Tech;

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

        abort_unless(auth()->user() && app(\App\Http\Middleware\EnforceTechRoutePermission::class)->allowsActor(auth()->user(), 'asset.view'), 403);
        $result = app(\App\Modules\Asset\Actions\SyncAssetAlerts::class)->handle(assetId: $this->asset->id);

        $this->asset->load('alerts');
        $this->isSyncing = false;

        $this->dispatch('refreshAlerts');
        session()->flash('alert_sync_success', $result['state'] === 'completed' ? 'Alerts updated successfully.' : 'Alert sync finished with skipped or failed providers.');
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
