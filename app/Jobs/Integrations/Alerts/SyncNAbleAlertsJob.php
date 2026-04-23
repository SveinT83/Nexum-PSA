<?php

namespace App\Jobs\Integrations\Alerts;

use App\Models\System\Integrations\Integration;
use App\Models\Tech\Work\Assets\Asset;
use App\Models\Tech\Work\Assets\AssetAlert;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncNAbleAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $integrationId;
    protected $assetId;

    /**
     * Create a new job instance.
     *
     * @param string $integrationId
     * @param int|null $assetId If provided, we still fetch the global feed but only update alerts for this asset
     */
    public function __construct(string $integrationId, ?int $assetId = null)
    {
        $this->integrationId = $integrationId;
        $this->assetId = $assetId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $integration = Integration::find($this->integrationId);
        if (!$integration || $integration->type !== 'rmm' || $integration->status !== 'active') {
            return;
        }

        $client = new NAbleRmmClient($integration);
        $failingChecks = $client->listFailingChecks();

        if (isset($failingChecks['error'])) {
            Log::error("N-able RMM SyncNAbleAlertsJob failed", ['error' => $failingChecks['error']]);
            return;
        }

        $activeFingerprintsFromApi = [];
        $affectedAssetIds = [];

        foreach ($failingChecks as $check) {
            $externalDeviceId = $check['deviceid'] ?? null;
            $externalCheckId = $check['checkid'] ?? null;

            if (!$externalDeviceId || !$externalCheckId) continue;

            // Find the asset in our system
            $asset = Asset::whereHas('rmmLinks', function ($query) use ($integration, $externalDeviceId) {
                $query->where('integration_id', $integration->id)
                      ->where('external_id', $externalDeviceId);
            })->first();

            if (!$asset) continue;

            // If we are targeting a specific asset, skip others
            if ($this->assetId && $asset->id !== $this->assetId) continue;

            $affectedAssetIds[] = $asset->id;
            $fingerprint = "nable:{$asset->id}:{$externalCheckId}";
            $activeFingerprintsFromApi[] = $fingerprint;

            $alert = AssetAlert::where('fingerprint', $fingerprint)->first();

            if ($alert) {
                if ($alert->status === 'resolved') {
                    $alert->update([
                        'status' => 'active',
                        'resolved_at' => null,
                        'last_seen_at' => now(),
                        'title' => $check['description'] ?? 'Check failing',
                        'message' => $check['description'] ?? '',
                    ]);
                } else {
                    $alert->update([
                        'last_seen_at' => now(),
                        'title' => $check['description'] ?? 'Check failing',
                        'message' => $check['description'] ?? '',
                    ]);
                }
            } else {
                AssetAlert::create([
                    'asset_id' => $asset->id,
                    'integration_type' => 'nable',
                    'external_check_id' => $externalCheckId,
                    'external_alert_id' => null, // Not provided by this endpoint
                    'fingerprint' => $fingerprint,
                    'title' => $check['description'] ?? 'Check failing',
                    'message' => $check['description'] ?? '',
                    'status' => 'active',
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]);
            }
        }

        // Resolve alerts that are no longer in the failing checks list
        // If we are targeting one asset, only resolve for that asset.
        // If bulk, resolve for ALL assets linked to this integration that were NOT in the current failing list.
        $affectedAssetIds = array_unique($affectedAssetIds);

        $resolveQuery = AssetAlert::where('integration_type', 'nable')
            ->where('status', 'active')
            ->whereNotIn('fingerprint', $activeFingerprintsFromApi);

        if ($this->assetId) {
            $resolveQuery->where('asset_id', $this->assetId);
        } else {
            // Only resolve alerts for assets that are linked to THIS integration
            $resolveQuery->whereHas('asset.rmmLinks', function($q) use ($integration) {
                $q->where('integration_id', $integration->id);
            });
        }

        $resolveQuery->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
    }
}
