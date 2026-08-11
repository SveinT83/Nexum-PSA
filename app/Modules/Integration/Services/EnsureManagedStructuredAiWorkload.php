<?php

namespace App\Modules\Integration\Services;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiWorkloadProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Provision one capability-isolated internal workload for an allowlisted domain purpose.
 */
class EnsureManagedStructuredAiWorkload
{
    public function handle(AiAgent $agent, string $managedBy, User $approver): AiWorkloadProfile
    {
        if ($managedBy !== AiWorkloadProfile::MANAGED_BY_STORAGE_SUPPLIER_ORDERS) {
            throw ValidationException::withMessages(['ai_agent_id' => 'The managed AI purpose is not supported.']);
        }

        $agent->loadMissing('provider');
        if (! $agent->is_active || ! in_array('storage', $agent->default_domains ?? [], true)) {
            throw ValidationException::withMessages([
                'ai_agent_id' => 'Select an active agent assigned to the Storage domain.',
            ]);
        }
        if (! $agent->provider || $agent->provider->status !== 'active') {
            throw ValidationException::withMessages([
                'ai_agent_id' => 'The selected Storage agent needs an active AI provider.',
            ]);
        }

        $model = $agent->model ?: $agent->provider->default_model;
        if (blank($model)) {
            throw ValidationException::withMessages([
                'ai_agent_id' => 'The selected Storage agent needs a model.',
            ]);
        }

        $isLocal = $agent->provider->provider_key === 'ollama';

        return DB::transaction(function () use ($agent, $managedBy, $approver, $model, $isLocal): AiWorkloadProfile {
            AiWorkloadProfile::query()
                ->where('managed_by', $managedBy)
                ->where('ai_agent_id', '!=', $agent->id)
                ->update(['is_active' => false]);

            $workload = AiWorkloadProfile::query()->updateOrCreate(
                [
                    'managed_by' => $managedBy,
                    'ai_agent_id' => $agent->id,
                ],
                [
                    'name' => 'Supplier Order AI · '.$agent->name,
                    'slug' => 'managed-storage-supplier-orders-agent-'.$agent->id,
                    'workload_type' => AiWorkloadProfile::TYPE_INTERNAL_MODEL,
                    'purpose' => 'Extract minimized supplier-order facts as deterministic-profile fallback.',
                    'ai_provider_id' => $agent->ai_provider_id,
                    'model' => $model,
                    'processing_mode' => $isLocal ? 'local_only' : 'privacy_relay',
                    'maximum_data_profile' => 'pseudonymized',
                    'abilities' => [],
                    'allowed_client_ids' => [],
                    'allowed_work_context_ids' => [],
                    'employee_identification_requested' => false,
                    'workforce_purpose' => null,
                    'workforce_transparency_reference' => null,
                    'is_approved' => true,
                    'is_active' => true,
                    'expires_at' => null,
                    'approved_by' => $approver->id,
                    'approved_at' => now(),
                    'created_by' => $approver->id,
                ],
            );

            return $workload->fresh(['agent.provider']);
        });
    }

    public function deactivate(string $managedBy): void
    {
        if ($managedBy !== AiWorkloadProfile::MANAGED_BY_STORAGE_SUPPLIER_ORDERS) {
            return;
        }

        AiWorkloadProfile::query()
            ->where('managed_by', $managedBy)
            ->update(['is_active' => false]);
    }
}
