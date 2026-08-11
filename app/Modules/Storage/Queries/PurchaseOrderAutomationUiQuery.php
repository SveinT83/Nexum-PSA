<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Services\StructuredAiWorkloadReadiness;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use Illuminate\Support\Collection;

class PurchaseOrderAutomationUiQuery
{
    public function __construct(private readonly StructuredAiWorkloadReadiness $workloadReadiness) {}

    /** @return Collection<int, AiAgent> */
    public function storageAgents(): Collection
    {
        return AiAgent::query()
            ->where('is_active', true)
            ->whereJsonContains('default_domains', 'storage')
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'))
            ->with('provider')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{available: bool, reason: string, workload: ?AiWorkloadProfile}
     */
    public function forPolicy(?PurchaseOrderAutomationPolicy $policy): array
    {
        if ($policy === null || $policy->ai_mode === 'off') {
            return $this->unavailable('AI is disabled in the current Storage policy.');
        }

        $status = $this->forWorkloadId($policy->ai_workload_profile_id);
        $status['reason'] = $status['available']
            ? 'The selected Storage agent is ready.'
            : implode(' ', [
                'The selected Storage agent is not ready for supplier-order assistance.',
                'Check that the agent and its provider/model are active, then save the settings again.',
            ]);

        return $status;
    }

    /**
     * @return array{available: bool, reason: string, workload: ?AiWorkloadProfile}
     */
    public function forImport(PurchaseOrderImport $import): array
    {
        $import->loadMissing('policyRevision');
        $effective = data_get($import->effective_policy_snapshot, 'policy');
        $snapshot = is_array($effective)
            ? $effective
            : ($import->policyRevision?->snapshot ?? []);

        if (($snapshot['ai_mode'] ?? 'off') === 'off') {
            return $this->unavailable('AI was disabled by the policy revision pinned to this import.');
        }

        return $this->forWorkloadId($snapshot['ai_workload_profile_id'] ?? null);
    }

    /**
     * @return array{available: bool, reason: string, workload: ?AiWorkloadProfile}
     */
    public function forWorkloadId(mixed $workloadId): array
    {
        if (! is_numeric($workloadId) || (int) $workloadId < 1) {
            return $this->unavailable('No governed internal AI workload is selected.');
        }

        $workload = AiWorkloadProfile::query()
            ->with(['agent.provider'])
            ->withCount('bindings')
            ->find((int) $workloadId);

        if ($workload === null) {
            return $this->unavailable('The selected AI workload no longer exists.');
        }

        $reason = $this->workloadReadiness->denialReason($workload);
        if ($reason !== null) {
            return [
                'available' => false,
                'reason' => 'The selected AI workload is not executable under current governance ('.$reason.').',
                'workload' => $workload,
            ];
        }

        return [
            'available' => true,
            'reason' => 'Governed internal AI workload is available.',
            'workload' => $workload,
        ];
    }

    /**
     * @return array{available: false, reason: string, workload: null}
     */
    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'workload' => null,
        ];
    }
}
