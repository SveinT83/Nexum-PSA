<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Support\ShippingTrackingLinkResolver;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\PurchaseShipmentTracking;
use App\Modules\Storage\Support\ShippingCarrierSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppendPurchaseShipmentTracking
{
    public function __construct(private readonly ShippingTrackingLinkResolver $trackingLinks) {}

    /** @param array<string, mixed> $data */
    public function handle(
        PurchaseShipment $shipment,
        array $data,
        User $actor
    ): PurchaseShipmentTracking {
        $validated = Validator::make($data, [
            'shipping_carrier_id' => ['nullable', 'integer', 'exists:shipping_carriers,id'],
            'tracking_number' => ['required', 'string', 'max:255'],
            'tracking_type' => [
                'nullable',
                Rule::in(['master', 'parcel', 'last_mile', 'other', 'legacy']),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'direct_url' => ['nullable', 'string', 'max:4096'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'metadata' => ['nullable', 'array'],
        ])->validate();

        $trackingNumber = trim($validated['tracking_number']);
        if ($trackingNumber === '') {
            throw ValidationException::withMessages([
                'tracking_number' => 'A tracking number is required.',
            ]);
        }

        return DB::transaction(function () use (
            $shipment,
            $validated,
            $trackingNumber,
            $actor
        ): PurchaseShipmentTracking {
            $purchaseOrder = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($shipment->purchase_order_id);
            $lockedShipment = PurchaseShipment::query()
                ->lockForUpdate()
                ->findOrFail($shipment->id);
            if ((int) $lockedShipment->purchase_order_id !== (int) $purchaseOrder->id
                || in_array($purchaseOrder->status, [
                    PurchaseOrder::STATUS_CLOSED,
                    PurchaseOrder::STATUS_CANCELLED,
                ], true)
                || $lockedShipment->status === PurchaseShipment::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'shipment' => 'Tracking cannot be appended to a closed or cancelled order or shipment.',
                ]);
            }
            if ($lockedShipment->trackings()
                ->whereRaw('LOWER(tracking_number) = ?', [mb_strtolower($trackingNumber)])
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'tracking_number' => 'This tracking number already exists on the shipment.',
                ]);
            }

            $carrierId = $validated['shipping_carrier_id'] ?? $lockedShipment->shipping_carrier_id;
            $carrier = $this->carrier($carrierId);
            $snapshot = ShippingCarrierSnapshot::from($carrier);
            $directUrl = isset($validated['direct_url'])
                ? trim((string) $validated['direct_url'])
                : null;
            if ($directUrl !== null && $directUrl !== '' && (
                ! $carrier
                || ! $this->trackingLinks->isAllowedUrl(
                    $directUrl,
                    $snapshot['carrier_allowed_hosts_snapshot'] ?? []
                )
            )) {
                throw ValidationException::withMessages([
                    'direct_url' => 'The direct tracking URL must use an allowlisted HTTPS carrier host.',
                ]);
            }

            $sortOrder = array_key_exists('sort_order', $validated)
                ? (int) $validated['sort_order']
                : ((int) $lockedShipment->trackings()->max('sort_order') + 1);

            $tracking = $lockedShipment->trackings()->create([
                'shipping_carrier_id' => $carrier?->id,
                'tracking_number' => $trackingNumber,
                'tracking_type' => $validated['tracking_type'] ?? 'parcel',
                'label' => $validated['label'] ?? null,
                'direct_url' => $directUrl ?: null,
                ...$snapshot,
                'sort_order' => $sortOrder,
                'metadata' => $validated['metadata'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            return $tracking->load(['shipment', 'carrier']);
        }, 3);
    }

    private function carrier(mixed $carrierId): ?ShippingCarrier
    {
        if (! $carrierId) {
            return null;
        }

        $carrier = ShippingCarrier::query()
            ->lockForUpdate()
            ->find((int) $carrierId);
        if (! $carrier || $carrier->lifecycle_state === ShippingCarrier::LIFECYCLE_INACTIVE) {
            throw ValidationException::withMessages([
                'shipping_carrier_id' => 'The selected carrier is unavailable for new tracking data.',
            ]);
        }

        return $carrier;
    }
}
