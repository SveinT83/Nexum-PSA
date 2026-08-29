<?php

namespace App\Jobs\Integrations\Alerts;

use App\Models\System\Integrations\Integration;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Integration\Actions\RecordRmmAlertObservation;
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
     * @param  int|null  $assetId  If provided, only sync alerts for this specific asset
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
        if (! $integration || $integration->type !== 'tactical_rmm' || $integration->status !== 'active') {
            return;
        }

        $server = trim((string) $integration->server);
        $apiKey = $integration->getSecret('api_key');
        if ($server === '' || ! is_string($apiKey) || trim($apiKey) === '') {
            Log::error('Tactical RMM alert sync skipped because credentials are unavailable.', [
                'integration_id' => $integration->id,
            ]);

            return;
        }

        $client = new TacticalRmmClient($server, $apiKey);

        if ($this->assetId) {
            $asset = Asset::find($this->assetId);
            if ($asset) {
                $this->syncAssetAlerts($asset, $integration, $client, $observations);
            }
        } else {
            // Bulk sync for all assets linked to this integration
            Asset::whereHas('rmmLinks', function ($query) use ($integration) {
                $query->where('integration_id', $integration->id);
            })->chunk(50, function ($assets) use ($integration, $client, $observations) {
                foreach ($assets as $asset) {
                    $this->syncAssetAlerts($asset, $integration, $client, $observations);
                }
            });
        }
    }

    protected function syncAssetAlerts(Asset $asset, Integration $integration, TacticalRmmClient $client, RecordRmmAlertObservation $observations): void
    {
        $rmmLink = $asset->rmmLinks()->where('integration_id', $integration->id)->first();
        if (! $rmmLink) {
            return;
        }

        $checks = $client->getAgentChecks($rmmLink->external_id);

        foreach ($checks as $check) {
            $externalCheckId = $check['id'] ?? null;
            if (! $externalCheckId) {
                continue;
            }

            $fingerprint = "tactical:{$asset->id}:{$externalCheckId}";

            // Tactical RMM nests check results in a 'check_result' array
            $checkResult = $check['check_result'] ?? [];
            $status = mb_strtolower(trim((string) ($checkResult['status'] ?? ($check['status'] ?? 'passing'))));
            $retcode = (int) ($checkResult['retcode'] ?? ($check['retcode'] ?? 0));
            $failCount = max(0, (int) ($checkResult['fail_count'] ?? ($check['fail_count'] ?? 0)));
            $failsB4Alert = max(1, (int) ($check['fails_b4_alert'] ?? 1));

            $isFailing = ($status === 'failing') || ($retcode !== 0 && $failCount >= $failsB4Alert);

            $rawSeverity = $check['severity'] ?? $check['priority'] ?? $checkResult['severity'] ?? null;
            $message = ($checkResult['stdout'] ?? $check['stdout'] ?? '')."\n".($checkResult['stderr'] ?? $check['stderr'] ?? '');

            $observations->handle($asset, [
                'active' => $isFailing,
                'integration_type' => 'tactical',
                'external_check_id' => $externalCheckId,
                'fingerprint' => $fingerprint,
                'title' => $check['readable_desc'] ?? 'Check failing',
                'message' => trim($message),
                'severity' => $rawSeverity,
                'provider_context' => [
                    'check_type' => $check['check_type'] ?? $check['type'] ?? null,
                    'raw_severity' => $rawSeverity,
                    'provider_status' => $status,
                    'return_code' => $retcode,
                    'failure_count' => $failCount,
                    'failures_before_alert' => $failsB4Alert,
                ],
            ]);
        }
    }
}
