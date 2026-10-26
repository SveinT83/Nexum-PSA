<?php

namespace App\Modules\Asset\Actions;

use App\Jobs\Integrations\Alerts\SyncNAbleAlertsJob;
use App\Jobs\Integrations\Alerts\SyncTacticalAlertsJob;
use App\Models\System\Integrations\Integration;
use App\Models\Tech\Work\Assets\Asset;

/** Run exact per-Asset jobs and expose observed outcomes, not dispatch acceptance. */
class SyncAssetAlerts
{
    public function handle(?int $clientId = null, ?int $assetId = null): array
    {
        $assets = Asset::query()->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($assetId, fn ($q) => $q->whereKey($assetId))->orderBy('id')->get();
        $results = [];
        foreach ($assets as $asset) {
            foreach (['rmm' => SyncNAbleAlertsJob::class, 'tactical_rmm' => SyncTacticalAlertsJob::class] as $type => $class) {
                $integration = Integration::where('type', $type)->where('status', 'active')->first();
                if (! $integration || ! $asset->rmmLinks()->where('integration_id', $integration->id)->exists()) {
                    continue;
                }
                $job = new $class((string) $integration->id, $asset->id);
                app()->call([$job, 'handle']);
                $results[] = ['asset_id' => $asset->id, 'provider' => $type] + $job->result;
            }
        }
        $states = array_column($results, 'state');
        $state = ! $results ? 'skipped' : (count(array_filter($states, fn ($s) => $s !== 'completed')) ? 'partial' : 'completed');

        return ['state' => $state, 'assets_considered' => $assets->count(), 'providers' => $results];
    }
}
