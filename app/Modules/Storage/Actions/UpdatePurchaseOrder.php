<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Support\SupplierOrderIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdatePurchaseOrder
{
    private const RESERVED_METADATA_KEYS = [
        'lifecycle_history',
    ];

    public function __construct(
        private readonly SyncPurchaseOrderLines $syncLines,
        private readonly RefreshPurchaseOrderStatus $refreshStatus,
        private readonly EnsurePurchaseOrderSupplierIdentityAvailable $identityAvailable,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(
        PurchaseOrder $purchaseOrder,
        array $data,
        User $actor,
        bool $allowConfirmedIdentityCorrection = false,
    ): PurchaseOrder {
        $data['currency'] = strtoupper((string) ($data['currency'] ?? $purchaseOrder->currency ?? 'NOK'));

        $validated = Validator::make($data, [
            'po_number' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('storage_purchase_orders', 'po_number')->ignore($purchaseOrder->id),
            ],
            'vendor_id' => ['sometimes', 'required', 'integer', 'exists:vendors,id'],
            'deliver_to_warehouse_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:storage_warehouses,id',
            ],
            'status' => ['sometimes', Rule::in([
                PurchaseOrder::STATUS_DRAFT,
                PurchaseOrder::STATUS_ORDERED,
            ])],
            'vendor_ref' => ['nullable', 'string', 'max:255'],
            'ordered_at' => ['nullable', 'date'],
            'expected_at' => ['nullable', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($purchaseOrder, $validated, $actor, $allowConfirmedIdentityCorrection): PurchaseOrder {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
            if (! in_array($locked->status, [
                PurchaseOrder::STATUS_DRAFT,
                PurchaseOrder::STATUS_ORDERED,
            ], true)) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Only draft or ordered purchase orders can be edited.',
                ]);
            }

            $requestedStatus = $validated['status'] ?? $locked->status;
            if ($locked->status === PurchaseOrder::STATUS_ORDERED
                && $requestedStatus === PurchaseOrder::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'A placed order cannot be changed back to a draft purchase need.',
                ]);
            }

            $vendorId = (int) ($validated['vendor_id'] ?? $locked->vendor_id);
            $warehouseId = (int) ($validated['deliver_to_warehouse_id'] ?? $locked->deliver_to_warehouse_id);
            $vendorRef = array_key_exists('vendor_ref', $validated)
                ? SupplierOrderIdentity::storedReference($validated['vendor_ref'])
                : SupplierOrderIdentity::storedReference($locked->vendor_ref);
            if (! $allowConfirmedIdentityCorrection
                && $locked->supplierOrderImport()->exists() && (
                    $vendorId !== (int) $locked->vendor_id
                    || $vendorRef !== SupplierOrderIdentity::storedReference($locked->vendor_ref)
                )) {
                throw ValidationException::withMessages([
                    'vendor_ref' => 'Supplier and supplier order number are locked after vendor confirmation.',
                ]);
            }
            $vendorChanged = $vendorId !== (int) $locked->vendor_id;
            $this->identityAvailable->handle($vendorId, $vendorRef, $locked->id);
            $warehouseChanged = $warehouseId !== (int) $locked->deliver_to_warehouse_id;
            $vendor = Vendor::query()->lockForUpdate()->find($vendorId);
            if (! $vendor) {
                throw ValidationException::withMessages([
                    'vendor_id' => 'The selected supplier is unavailable.',
                ]);
            }
            if ($vendorChanged && (! $vendor->is_supplier || ! $vendor->is_active)) {
                throw ValidationException::withMessages([
                    'vendor_id' => 'A replacement supplier must be an active supplier record.',
                ]);
            }

            $warehouse = Warehouse::withTrashed()
                ->lockForUpdate()
                ->find($warehouseId);
            if (! $warehouse) {
                throw ValidationException::withMessages([
                    'deliver_to_warehouse_id' => 'The selected destination warehouse is unavailable.',
                ]);
            }
            if ($warehouseChanged && ($warehouse->trashed() || ! $warehouse->is_active)) {
                throw ValidationException::withMessages([
                    'deliver_to_warehouse_id' => 'A replacement destination warehouse must be active.',
                ]);
            }
            $this->identityAvailable->handle($vendorId, $vendorRef, $locked->id);

            $hasOperationalHistory = $locked->shipments()->exists() || $locked->receipts()->exists();
            $currency = strtoupper((string) $validated['currency']);
            if ($hasOperationalHistory && (
                $vendorChanged
                || $warehouseChanged
                || $currency !== strtoupper((string) $locked->currency)
            )) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Supplier, destination, and currency are locked after shipment or receipt activity.',
                ]);
            }

            $orderedAt = $validated['ordered_at'] ?? $locked->ordered_at;
            if ($requestedStatus === PurchaseOrder::STATUS_ORDERED && ! $orderedAt) {
                throw ValidationException::withMessages([
                    'ordered_at' => 'Order date is required for an externally placed order.',
                ]);
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            if (isset($validated['metadata'])) {
                $metadata = array_replace(
                    $metadata,
                    Arr::except($validated['metadata'], self::RESERVED_METADATA_KEYS)
                );
            }

            $statusChanged = $requestedStatus !== $locked->status;
            $attributes = [
                'po_number' => $validated['po_number'] ?? $locked->po_number,
                'vendor_id' => $vendorId,
                'supplier_name_snapshot' => $vendorId !== (int) $locked->vendor_id
                    ? $vendor->name
                    : ($locked->supplier_name_snapshot ?: $vendor->name),
                'deliver_to_warehouse_id' => $warehouseId,
                'status' => $requestedStatus,
                'status_changed_at' => $statusChanged ? now() : $locked->status_changed_at,
                'status_changed_by' => $statusChanged ? $actor->id : $locked->status_changed_by,
                'vendor_ref' => $vendorRef,
                'ordered_at' => $orderedAt,
                'expected_at' => array_key_exists('expected_at', $validated)
                    ? $validated['expected_at']
                    : $locked->expected_at,
                'currency' => $currency,
                'notes' => array_key_exists('notes', $validated)
                    ? $validated['notes']
                    : $locked->notes,
                'metadata' => $metadata ?: null,
                'updated_by' => $actor->id,
            ];
            try {
                $locked->fill($attributes)->save();
            } catch (QueryException $exception) {
                EnsurePurchaseOrderSupplierIdentityAvailable::throwWhenConstraintWasRaced($exception);
            }

            if (array_key_exists('lines', $validated)) {
                $this->syncLines->handle($locked, $validated['lines'], $actor);
            }

            return $this->refreshStatus
                ->handle($locked, $actor)
                ->load(['vendor', 'deliverToWarehouse', 'lines.item']);
        }, 3);
    }
}
