<?php

namespace App\Modules\Storage\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StorageItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $permission = \App\Modules\Storage\Support\StorageApiAccess::allows($actor, 'storage.item_manage')
            ? 'storage.item_manage' : 'storage.view';
        if (! \App\Modules\Storage\Support\StorageApiAccess::allows($actor, $permission)) {
            return $this->resource->only(['id', 'qty_on_hand', 'qty_reserved', 'status']);
        }
        \App\Modules\Storage\Support\StorageApiAccess::retainResponseAuthority($request, [$permission], []);

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
            'manufacturer' => $this->manufacturer,
            'manufacturer_part_number' => $this->manufacturer_part_number,
            'ean_number' => $this->ean_number,
            'purchase_price' => $this->purchase_price,
            'markup_percent' => $this->markup_percent,
            'sale_price' => $this->sale_price,
            'vat_rate' => $this->vat_rate,
            'has_serials' => $this->has_serials,
            'track_batch' => $this->track_batch,
            'expiry_enabled' => $this->expiry_enabled,
            'becomes_asset' => $this->becomes_asset,
            'default_warranty_months' => $this->default_warranty_months,
            'reorder_point' => $this->reorder_point,
            'target_level' => $this->target_level,
            'lead_time_days' => $this->lead_time_days,
            'moq' => $this->moq,
            'qty_on_hand' => $this->qty_on_hand,
            'qty_reserved' => $this->qty_reserved,
            'qty_available' => $this->qty_available,
            'qty_incoming' => $this->whenHas('qty_incoming', fn () => (int) $this->qty_incoming),
            'needs_reorder' => $this->needs_reorder,
            'suggested_order_qty' => $this->suggested_order_qty,
            'should_order' => $this->should_order,
            'can_be_ordered' => $this->can_be_ordered,
            'requires_customer_quote' => $this->requires_customer_quote,
            'status' => $this->status,
            'warehouse_id' => $this->warehouse_id,
            'room_id' => $this->room_id,
            'box_id' => $this->box_id,
            'primary_vendor_id' => $this->primary_vendor_id,
            'manufacturer_vendor_id' => $this->manufacturer_vendor_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse?->id,
                'name' => $this->warehouse?->name,
                'code' => $this->warehouse?->code,
            ]),
            'box' => $this->whenLoaded('box', fn () => [
                'id' => $this->box?->id,
                'name' => $this->box?->name,
                'code_human' => $this->box?->code_human,
                'barcode_value' => $this->box?->barcode_value,
            ]),
            'primary_supplier' => $this->whenLoaded('primaryVendor', fn () => $this->primaryVendor?->only(['id', 'name', 'is_active', 'is_supplier'])),
            'manufacturer_vendor' => $this->whenLoaded('manufacturerVendor', fn () => $this->manufacturerVendor?->only(['id', 'name', 'is_active', 'is_manufacturer'])),
            'supplier_lines' => $this->whenLoaded('itemVendors', fn () => $this->itemVendors->map(fn ($line) => $line->only([
                'id', 'vendor_id', 'vendor_sku', 'purchase_url', 'unit_cost', 'currency', 'lead_time_days', 'moq', 'pack_size', 'is_primary',
            ]))),
            'movements' => $this->whenLoaded('movements', fn () => $this->movements->map(fn ($movement) => [
                ...$movement->only(['id', 'type', 'qty_before', 'qty_delta', 'qty_after', 'reason', 'created_at']),
                'actor' => $movement->actor?->only(['id', 'name']),
            ])),
            'tracking_configuration_locked' => $this->when($this->relationLoaded('movements'), fn () => $this->trackingConfigurationIsLocked()),
            'can_use_generic_adjustment' => $this->when($this->relationLoaded('movements'), fn () => ! $this->requiresUnitAwareInventoryMutation()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.storage.items.show', $this->id),
                'adjust' => route('api.v1.storage.items.adjust', $this->id),
            ],
        ];
    }
}
