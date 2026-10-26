<?php

namespace App\Modules\Asset\Livewire\Tech\Alerts;

use App\Models\Clients\Client;
use Livewire\Component;

class AlertSyncProcessor extends Component
{
    protected $listeners = [
        'syncAllClientAlerts' => 'syncAllClientAlerts',
        'syncAllAlerts' => 'syncAllAlerts',
    ];

    public function syncAllAlerts()
    {
        $this->syncScope();
    }

    public function syncAllClientAlerts($params)
    {
        $clientId = $params['client_id'] ?? null;
        if ($clientId && Client::whereKey($clientId)->exists()) {
            $this->syncScope((int) $clientId);
        }
    }

    private function syncScope(?int $clientId = null): void
    {
        abort_unless(auth()->user() && app(\App\Http\Middleware\EnforceTechRoutePermission::class)->allowsActor(auth()->user(), 'asset.view'), 403);
        $result = app(\App\Modules\Asset\Actions\SyncAssetAlerts::class)->handle(clientId: $clientId);
        $this->dispatch('notify', ['type' => $result['state'] === 'completed' ? 'success' : 'info',
            'message' => 'Alert sync finished: '.$result['state'].'.']);
        $this->dispatch('refreshAlerts');
    }

    public function render()
    {
        return '<div></div>';
    }
}
