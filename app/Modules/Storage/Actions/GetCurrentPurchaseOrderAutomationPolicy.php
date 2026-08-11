<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicyRevision;
use App\Modules\Storage\Support\StableJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class GetCurrentPurchaseOrderAutomationPolicy
{
    /** @return array{policy: PurchaseOrderAutomationPolicy, revision: PurchaseOrderAutomationPolicyRevision} */
    public function handle(): array
    {
        return DB::transaction(function (): array {
            $policy = PurchaseOrderAutomationPolicy::query()
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            if (! $policy) {
                try {
                    $policy = PurchaseOrderAutomationPolicy::query()->create([
                        'name' => 'Default supplier-order policy',
                        'is_current' => true,
                        'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_OFF,
                        'default_outcome' => 'needs_attention',
                        'ai_mode' => 'off',
                        'provider_outage_behavior' => 'needs_attention',
                        'advanced_rules' => [],
                        'revision_number' => 1,
                    ]);
                } catch (QueryException) {
                    $policy = PurchaseOrderAutomationPolicy::query()
                        ->where('is_current', true)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            // Database defaults are part of the immutable revision and must be loaded before snapshotting.
            $policy->refresh();

            $revision = $policy->revisions()
                ->where('revision_number', $policy->revision_number)
                ->first();

            if (! $revision) {
                $snapshot = $policy->revisionSnapshot();
                $revision = $policy->revisions()->create([
                    'revision_number' => $policy->revision_number,
                    'snapshot' => $snapshot,
                    'checksum' => StableJson::checksum($snapshot),
                    'reason' => 'Initial fail-closed policy.',
                    'created_by' => $policy->created_by,
                    'activated_at' => now(),
                ]);
            }

            return compact('policy', 'revision');
        });
    }
}
