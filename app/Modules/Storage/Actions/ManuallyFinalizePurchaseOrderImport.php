<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Support\PurchaseOrderImportManualMutationGuard;
use App\Modules\Storage\Support\SupplierOrderCanonicalValidator;
use App\Modules\Storage\Support\SupplierOrderPolicyDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManuallyFinalizePurchaseOrderImport
{
    public function __construct(
        private readonly ResolveEffectivePurchaseOrderAutomationPolicy $effectivePolicy,
        private readonly SupplierOrderCanonicalValidator $canonicalValidator,
        private readonly ResolveSupplierOrderItems $resolveItems,
        private readonly FinalizeImportedPurchaseOrder $finalize,
    ) {}

    public function handle(PurchaseOrderImport $import, User $actor): PurchaseOrder
    {
        if (! $actor->isActive()
            || ! $actor->can('storage.purchase_import_execute')
            || ! $actor->can('storage.purchase_manage')) {
            throw ValidationException::withMessages(['import' => 'You cannot finalize this supplier-order import.']);
        }

        /** @var array{purchase_order: ?PurchaseOrder, error: ?string} $result */
        $result = DB::transaction(function () use ($import, $actor): array {
            // Hold the import lock across resolution and finalization so the worker cannot interleave.
            $locked = PurchaseOrderImport::query()
                ->with(['policyRevision', 'profile', 'profileVersion'])
                ->lockForUpdate()->findOrFail($import->id);
            PurchaseOrderImportManualMutationGuard::ensureMutable($locked, 'import');

            if (! is_array($locked->effective_policy_snapshot) || ! $locked->policyRevision) {
                throw ValidationException::withMessages([
                    'effective_policy' => 'A pinned effective policy is required before manual finalization.',
                ]);
            }

            $pinnedPolicy = $this->effectivePolicy->handle(
                $locked,
                $this->effectivePolicy->fromPinnedRevision($locked->policyRevision),
                $locked->profile,
                $locked->profileVersion,
            );
            $manualPolicy = $pinnedPolicy->replicate();
            $manualPolicy->forceFill([
                'id' => $pinnedPolicy->id,
                'automation_user_id' => $actor->id,
                'default_outcome' => SupplierOrderPolicyDecision::REGISTER_ORDERED,
            ]);
            $validation = $this->canonicalValidator->validate(
                $locked->normalized_document ?? [],
                $manualPolicy,
                $locked->safe_source_snapshot ?? [],
            );
            if (! $validation->valid()) {
                throw ValidationException::withMessages([
                    'import' => 'Canonical source data must be corrected before finalization.',
                ]);
            }

            $items = $this->resolveItems->handle($locked, $manualPolicy, $actor);
            if (! $items->allResolved()) {
                return [
                    'purchase_order' => null,
                    'error' => 'Every source line must be resolved before finalization.',
                ];
            }

            $decision = new SupplierOrderPolicyDecision(
                outcome: SupplierOrderPolicyDecision::REGISTER_ORDERED,
                reasonCodes: [],
                facts: [
                    'manual_review' => true,
                    'manual_actor_id' => $actor->id,
                    'source_trust_reviewed' => true,
                    'validation_passed' => true,
                    'all_lines_resolved' => true,
                ],
            );
            $purchaseOrder = $this->finalize->handle($locked, $manualPolicy, $decision);

            return [
                'purchase_order' => $purchaseOrder,
                'error' => $purchaseOrder
                    ? null
                    : 'The import conflicts with an existing supplier order.',
            ];
        });

        if (! $result['purchase_order']) {
            throw ValidationException::withMessages([
                'import' => $result['error'] ?? 'The supplier-order import could not be finalized.',
            ]);
        }

        return $result['purchase_order'];
    }
}
