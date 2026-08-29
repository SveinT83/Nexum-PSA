<?php

namespace App\Jobs\Integrations\Alerts;

use App\Models\System\Integrations\Integration;
use App\Models\Tech\Work\Assets\Asset;
use App\Models\Tech\Work\Assets\AssetAlert;
use App\Modules\Integration\Actions\RecordRmmAlertObservation;
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
     * @param  int|null  $assetId  If provided, we still fetch the global feed but only update alerts for this asset
     */
    public function __construct(string $integrationId, ?int $assetId = null)
    {
        $this->integrationId = $integrationId;
        $this->assetId = $assetId;
    }

    /**
     * Execute the job.
     */
    public function handle(RecordRmmAlertObservation $observations): void
    {
        $integration = Integration::find($this->integrationId);
        if (! $integration || $integration->type !== 'rmm' || $integration->status !== 'active') {
            return;
        }

        $client = new NAbleRmmClient($integration);
        $failingChecks = $client->listFailingChecks();

        if (isset($failingChecks['error'])) {
            // Do not echo provider/client error strings: connection exceptions may contain
            // the query-string API key used by the upstream N-able endpoint.
            Log::error('N-able RMM SyncNAbleAlertsJob failed.', [
                'integration_id' => $integration->id,
            ]);

            return;
        }

        $activeFingerprintsFromApi = [];
        $affectedAssetIds = [];

        foreach ($failingChecks as $check) {
            $externalDeviceId = $check['deviceid'] ?? null;
            $externalCheckId = $check['checkid'] ?? null;

            if (! $externalDeviceId || ! $externalCheckId) {
                continue;
            }

            // Find the asset in our system
            $asset = Asset::whereHas('rmmLinks', function ($query) use ($integration, $externalDeviceId) {
                $query->where('integration_id', $integration->id)
                    ->where('external_id', $externalDeviceId);
            })->first();

            if (! $asset) {
                continue;
            }

            // If we are targeting a specific asset, skip others
            if ($this->assetId && $asset->id !== $this->assetId) {
                continue;
            }

            $affectedAssetIds[] = $asset->id;
            $fingerprint = "nable:{$asset->id}:{$externalCheckId}";
            $activeFingerprintsFromApi[] = $fingerprint;

            $rawSeverity = $check['severity'] ?? null;
            if (blank($rawSeverity)) {
                $rawSeverity = $check['priority'] ?? null;
            }
            $observations->handle($asset, [
                'active' => true,
                'integration_type' => 'nable',
                'external_check_id' => $externalCheckId,
                'external_alert_id' => null,
                'fingerprint' => $fingerprint,
                'title' => $check['description'] ?? 'Check failing',
                'message' => $check['description'] ?? '',
                'severity' => $rawSeverity,
                'provider_context' => [
                    'check_type' => $check['check_type'] ?? $check['checktype'] ?? null,
                    'raw_severity' => $rawSeverity,
                    'provider_status' => 'failing',
                ],
            ]);
        }

        // Resolve alerts that are no longer in the failing checks list
        // If we are targeting one asset, only resolve for that asset.
        // If bulk, resolve for ALL assets linked to this integration that were NOT in the current failing list.
        $affectedAssetIds = array_unique($affectedAssetIds);

        $resolveQuery = AssetAlert::query()
            ->where('integration_type', 'nable')
            ->whereNotIn('fingerprint', $activeFingerprintsFromApi)
            ->where(function ($states): void {
                $states->where('status', 'active')
                    ->orWhere(function ($resolved): void {
                        $resolved->where('status', 'resolved')
                            ->whereHas('occurrences', function ($occurrences): void {
                                $occurrences->whereNull('processed_at')
                                    ->where(function ($unfinished): void {
                                        $unfinished->whereIn('processing_status', ['pending', 'failed'])
                                            ->orWhere(function ($stale): void {
                                                $stale->where('processing_status', 'processing')
                                                    ->where('processing_started_at', '<=', now()->subMinutes(15));
                                            });
                                    });
                            });
                    });
            });

        if ($this->assetId) {
            $resolveQuery->where('asset_id', $this->assetId);
        } else {
            // Only resolve alerts for assets that are linked to THIS integration
            $resolveQuery->whereHas('asset.rmmLinks', function ($q) use ($integration) {
                $q->where('integration_id', $integration->id);
            });
        }

        $resolveQuery->with('asset')->eachById(function (AssetAlert $alert) use ($observations): void {
            $observations->resolve($alert);
        });
    }
}
