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

class StorePurchaseOrder
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
    public function handle(array $data, User $actor): PurchaseOrder
    {
        $data['status'] ??= PurchaseOrder::STATUS_ORDERED;
        $data['currency'] = strtoupper((string) ($data['currency'] ?? 'NOK'));

        $validated = Validator::make($data, [
            'po_number' => ['required', 'string', 'max:255', 'unique:storage_purchase_orders,po_number'],
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'deliver_to_warehouse_id' => ['required', 'integer', 'exists:storage_warehouses,id'],
            'status' => ['required', Rule::in([
                PurchaseOrder::STATUS_DRAFT,
                PurchaseOrder::STATUS_ORDERED,
            ])],
            'vendor_ref' => ['nullable', 'string', 'max:255'],
            'ordered_at' => ['nullable', 'date'],
            'expected_at' => ['nullable', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
        ])->validate();

        if ($validated['status'] === PurchaseOrder::STATUS_ORDERED && empty($validated['ordered_at'])) {
            throw ValidationException::withMessages([
                'ordered_at' => 'Order date is required for an externally placed order.',
            ]);
        }

        return DB::transaction(function () use ($validated, $actor): PurchaseOrder {
            $vendorId = (int) $validated['vendor_id'];
            $vendorRef = SupplierOrderIdentity::storedReference($validated['vendor_ref'] ?? null);
            // Lock the PO identity before related master data. Update follows the
            // same order so concurrent create/edit requests cannot deadlock.
            $this->identityAvailable->handle($vendorId, $vendorRef);

            $vendor = Vendor::query()->lockForUpdate()->find($vendorId);
            if (! $vendor || ! $vendor->is_supplier || ! $vendor->is_active) {
                throw ValidationException::withMessages([
                    'vendor_id' => 'The selected supplier must be an active supplier record.',
                ]);
            }
            $warehouse = Warehouse::withTrashed()
                ->lockForUpdate()
                ->find($validated['deliver_to_warehouse_id']);
            if (! $warehouse || $warehouse->trashed() || ! $warehouse->is_active) {
                throw ValidationException::withMessages([
                    'deliver_to_warehouse_id' => 'The selected destination warehouse must be active.',
                ]);
            }

            $metadata = is_array($validated['metadata'] ?? null)
                ? Arr::except($validated['metadata'], self::RESERVED_METADATA_KEYS)
                : [];

            $attributes = [
                'po_number' => trim($validated['po_number']),
                'vendor_id' => $vendor->id,
                'supplier_name_snapshot' => $vendor->name,
                'deliver_to_warehouse_id' => $validated['deliver_to_warehouse_id'],
                'status' => $validated['status'],
                'status_changed_at' => now(),
                'status_changed_by' => $actor->id,
                'vendor_ref' => $vendorRef,
                'ordered_at' => $validated['ordered_at'] ?? null,
                'expected_at' => $validated['expected_at'] ?? null,
                'currency' => $validated['currency'],
                'notes' => $validated['notes'] ?? null,
                'metadata' => $metadata ?: null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ];
            try {
                $purchaseOrder = PurchaseOrder::query()->create($attributes);
            } catch (QueryException $exception) {
                EnsurePurchaseOrderSupplierIdentityAvailable::throwWhenConstraintWasRaced($exception);
            }

            $this->syncLines->handle($purchaseOrder, $validated['lines'], $actor);
            $purchaseOrder = $this->refreshStatus->handle($purchaseOrder, $actor);

            return $purchaseOrder->load([
                'vendor',
                'deliverToWarehouse',
                'lines.item',
            ]);
        }, 3);
    }
}
