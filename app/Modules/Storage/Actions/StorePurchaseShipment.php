<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Support\ShippingTrackingLinkResolver;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderLine;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\PurchaseShipmentLine;
use App\Modules\Storage\Support\ShippingCarrierSnapshot;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StorePurchaseShipment
{
    private const RESERVED_METADATA_KEYS = [
        'status_history',
    ];

    public function __construct(private readonly ShippingTrackingLinkResolver $trackingLinks) {}

    /** @param array<string, mixed> $data */
    public function handle(PurchaseOrder $purchaseOrder, array $data, User $actor): PurchaseShipment
    {
        $data['status'] ??= PurchaseShipment::STATUS_PENDING;
        $data['allocations'] ??= [];
        $data['trackings'] ??= [];

        $validated = Validator::make($data, [
            'shipping_carrier_id' => ['nullable', 'integer', 'exists:shipping_carriers,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in([
                PurchaseShipment::STATUS_PENDING,
                PurchaseShipment::STATUS_IN_TRANSIT,
                PurchaseShipment::STATUS_DELIVERED,
            ])],
            'shipped_at' => ['nullable', 'date'],
            'expected_at' => ['nullable', 'date'],
            'delivered_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'allocations' => ['array', 'max:500'],
            'allocations.*.purchase_order_line_id' => ['required', 'integer'],
            'allocations.*.qty_allocated' => ['required', 'integer', 'min:1'],
            'trackings' => ['array', 'max:100'],
            'trackings.*.shipping_carrier_id' => ['nullable', 'integer', 'exists:shipping_carriers,id'],
            'trackings.*.tracking_number' => ['required', 'string', 'max:255'],
            'trackings.*.tracking_type' => [
                'nullable',
                Rule::in(['master', 'parcel', 'last_mile', 'other', 'legacy']),
            ],
            'trackings.*.label' => ['nullable', 'string', 'max:255'],
            'trackings.*.direct_url' => ['nullable', 'string', 'max:4096'],
            'trackings.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'trackings.*.metadata' => ['nullable', 'array'],
        ])->validate();

        if ($validated['status'] === PurchaseShipment::STATUS_PENDING
            && (! empty($validated['shipped_at']) || ! empty($validated['delivered_at']))) {
            throw ValidationException::withMessages([
                'status' => 'Pending shipments cannot have shipped or delivered dates.',
            ]);
        }
        if ($validated['status'] === PurchaseShipment::STATUS_IN_TRANSIT
            && ! empty($validated['delivered_at'])) {
            throw ValidationException::withMessages([
                'delivered_at' => 'In-transit shipments cannot have a delivered date.',
            ]);
        }
        if ($validated['status'] === PurchaseShipment::STATUS_DELIVERED
            && empty($validated['delivered_at'])) {
            throw ValidationException::withMessages([
                'delivered_at' => 'Delivered date is required for a delivered shipment.',
            ]);
        }
        if ($validated['status'] === PurchaseShipment::STATUS_DELIVERED
            && ! empty($validated['shipped_at'])
            && Carbon::parse($validated['shipped_at'])->gt(Carbon::parse($validated['delivered_at']))) {
            throw ValidationException::withMessages([
                'shipped_at' => 'The shipped date cannot be after the delivered date.',
            ]);
        }
        if ($validated['status'] === PurchaseShipment::STATUS_IN_TRANSIT
            && empty($validated['shipped_at'])) {
            $validated['shipped_at'] = now();
        }
        if ($validated['status'] === PurchaseShipment::STATUS_DELIVERED
            && empty($validated['shipped_at'])) {
            $validated['shipped_at'] = $validated['delivered_at'];
        }

        return DB::transaction(function () use ($purchaseOrder, $validated, $actor): PurchaseShipment {
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
            if (! in_array($lockedOrder->status, [
                PurchaseOrder::STATUS_ORDERED,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ], true)) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Shipments can only be added to placed orders with outstanding goods.',
                ]);
            }

            $carrier = $this->carrierForNewShipment($validated['shipping_carrier_id'] ?? null, 'shipping_carrier_id');
            $snapshot = ShippingCarrierSnapshot::from($carrier);
            $metadata = is_array($validated['metadata'] ?? null)
                ? Arr::except($validated['metadata'], self::RESERVED_METADATA_KEYS)
                : [];

            $shipment = PurchaseShipment::query()->create([
                'purchase_order_id' => $lockedOrder->id,
                'shipping_carrier_id' => $carrier?->id,
                'reference' => $validated['reference'] ?? null,
                'status' => $validated['status'],
                ...$snapshot,
                'shipped_at' => $validated['shipped_at'] ?? null,
                'expected_at' => $validated['expected_at'] ?? null,
                'delivered_at' => $validated['delivered_at'] ?? null,
                'status_changed_at' => now(),
                'status_changed_by' => $actor->id,
                'notes' => $validated['notes'] ?? null,
                'metadata' => $metadata ?: null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $seenLineIds = [];
            foreach (array_values($validated['allocations']) as $index => $allocation) {
                $lineId = (int) $allocation['purchase_order_line_id'];
                if (in_array($lineId, $seenLineIds, true)) {
                    throw ValidationException::withMessages([
                        "allocations.$index.purchase_order_line_id" => 'Each order line may only be allocated once per shipment.',
                    ]);
                }
                $seenLineIds[] = $lineId;

                $line = PurchaseOrderLine::query()->lockForUpdate()->find($lineId);
                if (! $line || (int) $line->purchase_order_id !== (int) $lockedOrder->id) {
                    throw ValidationException::withMessages([
                        "allocations.$index.purchase_order_line_id" => 'The allocation line does not belong to this purchase order.',
                    ]);
                }

                $activeAllocations = PurchaseShipmentLine::query()
                    ->where('purchase_order_line_id', $line->id)
                    ->whereHas('shipment', fn ($query) => $query->where(
                        'status',
                        '<>',
                        PurchaseShipment::STATUS_CANCELLED
                    ))
                    ->lockForUpdate()
                    ->get(['qty_allocated', 'qty_received', 'qty_rejected', 'qty_cancelled']);
                $unreceivedAllocated = (int) $activeAllocations->sum(
                    fn (PurchaseShipmentLine $line): int => $line->qty_outstanding
                );
                $availableToAllocate = max(0, $line->qty_outstanding - $unreceivedAllocated);
                $qty = (int) $allocation['qty_allocated'];

                if ($qty > $availableToAllocate) {
                    throw ValidationException::withMessages([
                        "allocations.$index.qty_allocated" => 'Active shipment allocations cannot exceed the ordered quantity.',
                    ]);
                }

                $shipment->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'qty_allocated' => $qty,
                    'qty_received' => 0,
                    'qty_rejected' => 0,
                    'qty_cancelled' => 0,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            $seenTrackingNumbers = [];
            foreach (array_values($validated['trackings']) as $index => $tracking) {
                $trackingNumber = trim($tracking['tracking_number']);
                $normalizedNumber = mb_strtolower($trackingNumber);
                if (in_array($normalizedNumber, $seenTrackingNumbers, true)) {
                    throw ValidationException::withMessages([
                        "trackings.$index.tracking_number" => 'Each tracking number may only appear once per shipment.',
                    ]);
                }
                $seenTrackingNumbers[] = $normalizedNumber;

                $trackingCarrierId = $tracking['shipping_carrier_id'] ?? $carrier?->id;
                $trackingCarrier = $this->carrierForNewShipment(
                    $trackingCarrierId,
                    "trackings.$index.shipping_carrier_id"
                );
                $trackingSnapshot = ShippingCarrierSnapshot::from($trackingCarrier);
                $directUrl = isset($tracking['direct_url'])
                    ? trim((string) $tracking['direct_url'])
                    : null;

                if ($directUrl !== null && $directUrl !== '' && (
                    ! $trackingCarrier
                    || ! $this->trackingLinks->isAllowedUrl(
                        $directUrl,
                        $trackingSnapshot['carrier_allowed_hosts_snapshot'] ?? []
                    )
                )) {
                    throw ValidationException::withMessages([
                        "trackings.$index.direct_url" => 'The direct tracking URL must use an allowlisted HTTPS carrier host.',
                    ]);
                }

                $shipment->trackings()->create([
                    'shipping_carrier_id' => $trackingCarrier?->id,
                    'tracking_number' => $trackingNumber,
                    'tracking_type' => $tracking['tracking_type'] ?? 'parcel',
                    'label' => $tracking['label'] ?? null,
                    'direct_url' => $directUrl ?: null,
                    ...$trackingSnapshot,
                    'sort_order' => (int) ($tracking['sort_order'] ?? $index),
                    'metadata' => $tracking['metadata'] ?? null,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            return $shipment->load([
                'carrier',
                'lines.purchaseOrderLine.item',
                'trackings.carrier',
            ]);
        });
    }

    private function carrierForNewShipment(mixed $carrierId, string $field): ?ShippingCarrier
    {
        if (! $carrierId) {
            return null;
        }

        $carrier = ShippingCarrier::query()->lockForUpdate()->find((int) $carrierId);
        if (! $carrier || $carrier->lifecycle_state === ShippingCarrier::LIFECYCLE_INACTIVE) {
            throw ValidationException::withMessages([
                $field => 'The selected carrier is not available for a new shipment.',
            ]);
        }

        return $carrier;
    }
}
