<?php

namespace App\Modules\Asset\Livewire\Tech;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Models\Tech\Work\Assets\AssetAlert;
use Livewire\Component;

class ClientAlertsSummary extends Component
{
    public ?Client $client = null;
    public $isSyncing = false;

    protected $listeners = [
        'refreshAlerts' => '$refresh'
    ];

    public function mount($client = null)
    {
        // Accept both a bound Client model and a scalar ID because this summary
        // is reused from global asset pages and client-scoped pages.
        if ($client instanceof Client) {
            $this->client = $client;
        } elseif ($client) {
            $this->client = Client::find($client);
        }
    }

    public function syncAlerts()
    {
        $this->isSyncing = true;

        // The hidden AlertSyncProcessor listens for these browser events and
        // runs the concrete RMM jobs. This component only chooses the scope.
        if ($this->client) {
            $this->dispatch('syncAllClientAlerts', ['client_id' => $this->client->id]);
        } else {
            $this->dispatch('syncAllAlerts');
        }

        $this->isSyncing = false;
        $this->dispatch('refreshAlerts');
        session()->flash('alert_sync_success', 'Alerts updated successfully.');
    }

    public function render()
    {
        $query = AssetAlert::where('status', 'active');

        if ($this->client) {
            $query->whereHas('asset', function($q) {
                $q->where('client_id', $this->client->id);
            });
        }

        $activeCount = $query->count();

        $resolvedQuery = AssetAlert::where('status', 'resolved');
        if ($this->client) {
            $resolvedQuery->whereHas('asset', function($q) {
                $q->where('client_id', $this->client->id);
            });
        }
        $resolvedCount = $resolvedQuery->where('resolved_at', '>=', now()->subDays(1))->count();

        return view('asset::Livewire.Tech.Work.client-alerts-summary', [
            'activeCount' => $activeCount,
            'resolvedCount' => $resolvedCount,
        ]);
    }
}
