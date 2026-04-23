<?php

namespace App\Jobs\Integrations\Alerts;

use App\Models\System\Integrations\Integration;
use App\Models\Tech\Work\Assets\Asset;
use App\Models\Tech\Work\Assets\AssetAlert;
use App\Services\Integrations\TacticalRmm\TacticalRmmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncTacticalAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $integrationId;
    protected $assetId;

    /**
     * Create a new job instance.
     *
     * @param string $integrationId
     * @param int|null $assetId If provided, only sync alerts for this specific asset
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
        if (!$integration || $integration->type !== 'tactical_rmm' || $integration->status !== 'active') {
            return;
        }

        $client = new TacticalRmmClient($integration->server, $integration->getSecret('api_key'));

        if ($this->assetId) {
            $asset = Asset::find($this->assetId);
            if ($asset) {
                $this->syncAssetAlerts($asset, $integration, $client);
            }
        } else {
            // Bulk sync for all assets linked to this integration
            Asset::whereHas('rmmLinks', function ($query) use ($integration) {
                $query->where('integration_id', $integration->id);
            })->chunk(50, function ($assets) use ($integration, $client) {
                foreach ($assets as $asset) {
                    $this->syncAssetAlerts($asset, $integration, $client);
                }
            });
        }
    }

    protected function syncAssetAlerts(Asset $asset, Integration $integration, TacticalRmmClient $client): void
    {
        $rmmLink = $asset->rmmLinks()->where('integration_id', $integration->id)->first();
        if (!$rmmLink) {
            return;
        }

        $checks = $client->getAgentChecks($rmmLink->external_id);

        $activeFingerprints = [];

        foreach ($checks as $check) {
            $externalCheckId = $check['id'] ?? null;
            if (!$externalCheckId) continue;

            $fingerprint = "tactical:{$asset->id}:{$externalCheckId}";

            // Tactical RMM nests check results in a 'check_result' array
            $checkResult = $check['check_result'] ?? [];
            $status = $checkResult['status'] ?? ($check['status'] ?? 'passing');
            $retcode = $checkResult['retcode'] ?? ($check['retcode'] ?? 0);
            $failCount = $checkResult['fail_count'] ?? ($check['fail_count'] ?? 0);
            $failsB4Alert = $check['fails_b4_alert'] ?? 1;

            $isFailing = ($status === 'failing') || ($retcode !== 0 && $failCount >= $failsB4Alert);

            if ($isFailing) {
                $activeFingerprints[] = $fingerprint;

                $alert = AssetAlert::where('fingerprint', $fingerprint)->first();

                $title = $check['readable_desc'] ?? 'Check failing';
                $message = ($checkResult['stdout'] ?? $check['stdout'] ?? '') . "\n" . ($checkResult['stderr'] ?? $check['stderr'] ?? '');

                if ($alert) {
                    if ($alert->status === 'resolved') {
                        $alert->update([
                            'status' => 'active',
                            'resolved_at' => null,
                            'last_seen_at' => now(),
                            'title' => $title,
                            'message' => trim($message),
                        ]);
                    } else {
                        $alert->update([
                            'last_seen_at' => now(),
                            'title' => $title,
                            'message' => trim($message),
                        ]);
                    }
                } else {
                    AssetAlert::create([
                        'asset_id' => $asset->id,
                        'integration_type' => 'tactical',
                        'external_check_id' => $externalCheckId,
                        'fingerprint' => $fingerprint,
                        'title' => $title,
                        'message' => trim($message),
                        'status' => 'active',
                        'first_seen_at' => now(),
                        'last_seen_at' => now(),
                    ]);
                }
            } else {
                // If check is OK, resolve any active alert for this check
                AssetAlert::where('fingerprint', $fingerprint)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'resolved',
                        'resolved_at' => now(),
                    ]);
            }
        }
    }
}
