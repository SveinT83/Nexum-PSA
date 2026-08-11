<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Support\SupplierOrderCanonicalValidator;
use App\Modules\Storage\Support\SupplierOrderConfirmationComparator;
use App\Modules\Storage\Support\SupplierOrderIdentity;
use App\Modules\Storage\Support\SupplierOrderImportProjectionGuard;
use App\Modules\Storage\Support\SupplierOrderPolicyDecision;
use App\Modules\Storage\Support\SupplierOrderSourceIntegrity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinalizeImportedPurchaseOrder
{
    public function __construct(
        private readonly StorePurchaseOrder $storePurchaseOrder,
        private readonly SupplierOrderCanonicalValidator $canonicalValidator,
        private readonly SupplierOrderConfirmationComparator $confirmationComparator,
        private readonly SupplierOrderImportProjectionGuard $projectionGuard,
        private readonly SupplierOrderSourceIntegrity $sourceIntegrity,
    ) {}

    public function handle(
        PurchaseOrderImport $import,
        PurchaseOrderAutomationPolicy $policy,
        SupplierOrderPolicyDecision $decision,
    ): ?PurchaseOrder {
        if (! $decision->permitsPurchaseOrderWrite()) {
            return null;
        }

        return DB::transaction(function () use ($import, $policy, $decision): ?PurchaseOrder {
            $locked = PurchaseOrderImport::query()
                ->with(['lines.item', 'vendor', 'profileVersion'])
                ->lockForUpdate()
                ->findOrFail($import->id);
            if ($locked->purchase_order_id) {
                return PurchaseOrder::query()->find($locked->purchase_order_id);
            }
            $this->sourceIntegrity->validateOrFail(
                $locked->safe_source_snapshot ?? [],
                (string) $locked->source_fingerprint,
                $locked->trusted_auth_snapshot ?? [],
            );

            $document = $locked->normalized_document ?? [];
            $validation = $locked->extraction_method === 'ai'
                ? $this->canonicalValidator->validateAiDocument(
                    $document,
                    $policy,
                    $locked->safe_source_snapshot ?? [],
                    $locked->source_fingerprint,
                )
                : $this->canonicalValidator->validate(
                    $document,
                    $policy,
                    $locked->safe_source_snapshot ?? [],
                );
            if (! $validation->valid()) {
                throw ValidationException::withMessages([
                    'purchase_order' => collect($validation->errors)->take(30)->map(
                        fn (array $error): string => ($error['code'] ?? 'canonical_validation_failed')
                            .': '.($error['path'] ?? 'document'),
                    )->values()->all(),
                ]);
            }
            $this->projectionGuard->validateOrFail($locked, $document);

            $actor = $this->automationActor($policy);
            $vendorId = (int) $locked->vendor_id;
            $externalOrder = SupplierOrderIdentity::storedReference(
                data_get($document, 'external_order_number'),
            );
            if ($externalOrder === null || $externalOrder
                !== SupplierOrderIdentity::storedReference($locked->external_order_number)) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'source_projection_mismatch: external_order_number',
                ]);
            }
            $warehouseId = (int) data_get($document, 'destination_warehouse_id');
            $warehouse = Warehouse::query()->where('is_active', true)->find($warehouseId);
            if (! $locked->vendor?->is_supplier || ! $locked->vendor?->is_active || ! $warehouse) {
                throw ValidationException::withMessages(['purchase_order' => 'Supplier or destination warehouse is unavailable.']);
            }
            if ($locked->lines->isEmpty()) {
                throw ValidationException::withMessages(['purchase_order' => 'External order identity and lines are required.']);
            }
            if ($locked->lines->contains(fn ($line): bool => ! $line->item
                || $line->mapping_status !== 'resolved'
                || $line->item->status !== 'active'
                || ! $line->item->can_be_ordered
                || (int) $line->item->warehouse_id !== $warehouseId)) {
                throw ValidationException::withMessages(['purchase_order' => 'Every source line must resolve to an active orderable Item.']);
            }

            $domainHash = SupplierOrderIdentity::hash($vendorId, $externalOrder);
            if ($domainHash === null) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Supplier order identity could not be derived.',
                ]);
            }
            $conflict = PurchaseOrderImport::query()
                ->where('domain_identity_hash', $domainHash)
                ->whereKeyNot($locked->id)
                ->lockForUpdate()
                ->first();
            if ($conflict) {
                return $this->handleDomainConflict($locked, $conflict);
            }

            try {
                $locked->forceFill(['domain_identity_hash' => $domainHash])->save();
            } catch (QueryException $exception) {
                $conflict = PurchaseOrderImport::query()
                    ->where('domain_identity_hash', $domainHash)
                    ->whereKeyNot($locked->id)
                    ->lockForUpdate()
                    ->first();
                if ($conflict) {
                    return $this->handleDomainConflict($locked, $conflict);
                }
                throw $exception;
            }

            $existingPurchaseOrder = $this->purchaseOrderByIdentity($vendorId, $externalOrder);
            $this->assertExistingConfirmationResourcesAvailable($locked, $vendorId, $warehouseId);
            if ($existingPurchaseOrder !== null) {
                return $this->reconcileExistingPurchaseOrder(
                    $locked, $existingPurchaseOrder, $document, $policy, $decision, $actor
                );
            }
            $orderedAt = $this->orderDate($document, $locked);
            $currency = strtoupper((string) data_get($document, 'currency', 'NOK'));
            $status = $decision->outcome === SupplierOrderPolicyDecision::REGISTER_ORDERED
                ? PurchaseOrder::STATUS_ORDERED
                : PurchaseOrder::STATUS_DRAFT;
            try {
                $purchaseOrder = $this->storePurchaseOrder->handle([
                    'po_number' => $this->internalPoNumber($locked),
                    'vendor_id' => $vendorId,
                    'deliver_to_warehouse_id' => $warehouseId,
                    'status' => $status,
                    'vendor_ref' => $externalOrder,
                    'ordered_at' => $orderedAt,
                    'expected_at' => data_get($document, 'delivery.expected_at'),
                    'currency' => $currency,
                    'notes' => $this->notes($locked),
                    'metadata' => [
                        'created_from' => 'supplier_order_email_import',
                        'supplier_order_import_id' => $locked->id,
                        'source_fingerprint' => $locked->source_fingerprint,
                        'external_order_number' => $externalOrder,
                        'profile_version_id' => $locked->profile_version_id,
                        'policy_revision_id' => $locked->policy_revision_id,
                        'decision' => $decision->toArray(),
                        'commercial_snapshot' => $locked->commercial_snapshot,
                        'delivery_snapshot' => $locked->delivery_snapshot,
                    ],
                    'lines' => $locked->lines->map(fn ($line): array => [
                        'item_id' => $line->item_id,
                        'qty_ordered' => (int) $line->quantity,
                        'supplier_sku' => $line->supplier_sku,
                        'unit_cost' => $this->sourceUnitCost($line),
                        'tax_rate' => $line->tax_rate === null ? null : round((float) $line->tax_rate, 2),
                        'expected_at' => data_get($document, 'delivery.expected_at'),
                        'metadata' => [
                            'supplier_order_import_line_id' => $line->id,
                            'source_row_identifier' => $line->source_row_identifier,
                            'resolution_method' => $line->resolution_method,
                            'source_line_total' => $line->line_total,
                            'source_unit_cost_basis' => $line->unit_price === null ? 'line_total_divided_by_quantity' : 'unit_price',
                        ],
                    ])->all(),
                ], $actor);
            } catch (ValidationException $exception) {
                if (! array_key_exists('vendor_ref', $exception->errors())) {
                    throw $exception;
                }

                $racedPurchaseOrder = $this->currentPurchaseOrderByIdentity($vendorId, $externalOrder);
                if ($racedPurchaseOrder === null) {
                    throw $exception;
                }

                return $this->reconcileExistingPurchaseOrder(
                    $locked, $racedPurchaseOrder, $document, $policy, $decision, $actor
                );
            }

            $locked->forceFill([
                'purchase_order_id' => $purchaseOrder->id,
                'status' => PurchaseOrderImport::STATUS_IMPORTED,
                'stage' => PurchaseOrderImport::STAGE_FINALIZE,
                'reason_code' => null,
                'reason_context' => null,
                'decision' => $decision->outcome,
                'finalized_at' => now(),
                'processed_at' => now(),
                'locked_at' => null,
                'last_actor_id' => $actor->id,
            ])->save();

            return $purchaseOrder;
        }, 3);
    }

    private function purchaseOrderByIdentity(
        int $vendorId,
        string $supplierOrderNumber,
    ): ?PurchaseOrder {
        // Avoid a gap lock when no manual order exists; the database unique
        // constraint remains the race boundary before a new order is inserted.
        $purchaseOrderId = $this->purchaseOrderIdentityQuery($vendorId, $supplierOrderNumber)
            ->value('id');
        if ($purchaseOrderId === null) {
            return null;
        }

        $purchaseOrder = $this->purchaseOrderIdentityQuery($vendorId, $supplierOrderNumber)
            ->whereKey($purchaseOrderId)
            ->lockForUpdate()
            ->first();
        if ($purchaseOrder === null) {
            return null;
        }

        $purchaseOrder->setRelation(
            'lines',
            $purchaseOrder->lines()->orderBy('id')->lockForUpdate()->get(),
        );

        return $purchaseOrder;
    }

    private function currentPurchaseOrderByIdentity(
        int $vendorId,
        string $supplierOrderNumber,
    ): ?PurchaseOrder {
        // A locking read bypasses an older REPEATABLE READ snapshot after a
        // concurrent manual insert won the database uniqueness race.
        $purchaseOrder = $this->purchaseOrderIdentityQuery($vendorId, $supplierOrderNumber)
            ->lockForUpdate()
            ->first();
        if ($purchaseOrder === null) {
            return null;
        }

        $purchaseOrder->setRelation(
            'lines',
            $purchaseOrder->lines()->orderBy('id')->lockForUpdate()->get(),
        );

        return $purchaseOrder;
    }

    private function purchaseOrderIdentityQuery(
        int $vendorId,
        string $supplierOrderNumber,
    ): Builder {
        return PurchaseOrder::withTrashed()
            ->where('vendor_id', $vendorId)
            ->whereRaw(
                "supplier_order_identity_key = NULLIF(UPPER(TRIM(?)), '')",
                [$supplierOrderNumber],
            );
    }

    private function reconcileExistingPurchaseOrder(
        PurchaseOrderImport $import,
        PurchaseOrder $purchaseOrder,
        array $document,
        PurchaseOrderAutomationPolicy $policy,
        SupplierOrderPolicyDecision $decision,
        User $actor,
    ): ?PurchaseOrder {
        if ($purchaseOrder->trashed() || $purchaseOrder->status === PurchaseOrder::STATUS_CANCELLED) {
            $this->markExistingPurchaseOrderAttention(
                $import,
                $purchaseOrder,
                'existing_purchase_order_not_confirmable',
                ['purchase_order_not_active'],
                $actor,
            );

            return null;
        }

        $differences = $this->confirmationComparator->differences(
            $purchaseOrder,
            $import,
            $document,
            $policy,
        );
        if ($differences !== []) {
            $this->markExistingPurchaseOrderAttention(
                $import,
                $purchaseOrder,
                'existing_purchase_order_confirmation_mismatch',
                $differences,
                $actor,
            );

            return null;
        }

        // The email confirms provenance only. Manual lifecycle and commercial snapshots remain untouched.
        $import->forceFill([
            'purchase_order_id' => $purchaseOrder->id,
            'status' => PurchaseOrderImport::STATUS_IMPORTED,
            'stage' => PurchaseOrderImport::STAGE_FINALIZE,
            'reason_code' => 'existing_purchase_order_vendor_confirmed',
            'reason_context' => [
                'purchase_order_id' => $purchaseOrder->id,
                'matched_by' => 'supplier_and_supplier_order_number',
                'purchase_order_preserved' => true,
            ],
            'decision' => $decision->outcome,
            'finalized_at' => now(),
            'processed_at' => now(),
            'locked_at' => null,
            'last_actor_id' => $actor->id,
        ])->save();

        return $purchaseOrder->fresh(['vendor', 'deliverToWarehouse', 'lines.item']);
    }

    /**
     * Keep the import as the reviewable exception without linking or mutating
     * the candidate order. Its domain hash still prevents a second PO.
     *
     * @param  list<string>  $differences
     */
    private function markExistingPurchaseOrderAttention(
        PurchaseOrderImport $import,
        PurchaseOrder $purchaseOrder,
        string $reasonCode,
        array $differences,
        User $actor,
    ): void {
        $import->forceFill([
            'status' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
            'stage' => PurchaseOrderImport::STAGE_FINALIZE,
            'reason_code' => $reasonCode,
            'reason_context' => [
                'candidate_purchase_order_id' => $purchaseOrder->id,
                'candidate_po_number' => $purchaseOrder->po_number,
                'candidate_status' => $purchaseOrder->status,
                'candidate_deleted' => $purchaseOrder->trashed(),
                'differences' => $differences,
            ],
            'processed_at' => now(),
            'locked_at' => null,
            'last_actor_id' => $actor->id,
        ])->save();
    }

    private function assertExistingConfirmationResourcesAvailable(
        PurchaseOrderImport $import,
        int $vendorId,
        int $warehouseId,
    ): void {
        $vendor = Vendor::query()->lockForUpdate()->find($vendorId);
        $warehouse = Warehouse::withTrashed()->lockForUpdate()->find($warehouseId);
        $itemIds = $import->lines->pluck('item_id')->filter()->map(
            fn (mixed $id): int => (int) $id,
        )->unique()->sort()->values();
        $items = Item::query()
            ->whereIn('id', $itemIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $invalidItem = $import->lines->contains(function (mixed $line) use ($items, $warehouseId): bool {
            $item = $items->get((int) $line->item_id);

            return ! $item
                || $item->status !== 'active'
                || ! $item->can_be_ordered
                || (int) $item->warehouse_id !== $warehouseId;
        });
        if (! $vendor?->is_supplier || ! $vendor?->is_active
            || ! $warehouse || $warehouse->trashed() || ! $warehouse->is_active
            || $invalidItem) {
            throw ValidationException::withMessages([
                'purchase_order' => 'Supplier, destination, or mapped Item changed before confirmation could be linked.',
            ]);
        }
    }

    private function sourceUnitCost(mixed $line): ?float
    {
        if ($line->unit_price !== null) {
            return round((float) $line->unit_price, 2);
        }

        $quantity = (int) $line->quantity;
        if ($line->line_total === null || $quantity < 1) {
            return null;
        }

        // Purchase-order unit costs are stored at two decimals; retain the exact source total in metadata.
        return round((float) $line->line_total / $quantity, 2);
    }

    private function automationActor(PurchaseOrderAutomationPolicy $policy): User
    {
        $actor = $policy->automation_user_id ? User::query()->find($policy->automation_user_id) : null;
        if (! SupplierOrderAutomationActor::canAct($actor, 'storage.purchase_manage')) {
            throw ValidationException::withMessages([
                'automation' => 'The managed supplier-order automation authority is unavailable.',
            ]);
        }

        return $actor;
    }

    private function handleDomainConflict(PurchaseOrderImport $import, PurchaseOrderImport $conflict): ?PurchaseOrder
    {
        $sameSource = hash_equals($conflict->source_fingerprint, $import->source_fingerprint);
        $import->forceFill([
            'revision_of_import_id' => $conflict->id,
            'status' => $sameSource
                ? PurchaseOrderImport::STATUS_DUPLICATE
                : PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
            'stage' => PurchaseOrderImport::STAGE_FINALIZE,
            'reason_code' => $sameSource ? 'duplicate_supplier_order' : 'changed_supplier_order_resend',
            'reason_context' => ['conflicting_import_id' => $conflict->id],
            'processed_at' => now(),
            'locked_at' => null,
        ])->save();

        return $sameSource && $conflict->purchase_order_id
            ? PurchaseOrder::query()->find($conflict->purchase_order_id)
            : null;
    }

    private function orderDate(array $document, PurchaseOrderImport $import): string
    {
        $value = data_get($document, 'ordered_at');
        if (filled($value)) {
            return CarbonImmutable::parse((string) $value)->toDateString();
        }

        if (data_get($document, 'ordered_at_provenance') !== 'received_at_fallback') {
            throw ValidationException::withMessages([
                'purchase_order' => 'Order date is missing and no received-date fallback was approved.',
            ]);
        }

        $received = data_get($import->safe_source_snapshot, 'received_at');
        if (blank($received)) {
            throw ValidationException::withMessages([
                'purchase_order' => 'Order date fallback requires the pinned source received_at value.',
            ]);
        }

        try {
            return CarbonImmutable::parse((string) $received)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['purchase_order' => 'Pinned source received_at is invalid.']);
        }
    }

    private function internalPoNumber(PurchaseOrderImport $import): string
    {
        return 'AUTO-'.now()->format('Y').'-'.str_pad((string) $import->id, 8, '0', STR_PAD_LEFT);
    }

    private function notes(PurchaseOrderImport $import): string
    {
        $totals = $import->commercial_snapshot ?? [];

        return collect([
            'Automatically recorded from supplier order confirmation.',
            filled($totals['freight'] ?? null) ? 'Source freight: '.$totals['freight'] : null,
            filled($totals['discount'] ?? null) ? 'Source discount: '.$totals['discount'] : null,
        ])->filter()->implode("\n");
    }
}
