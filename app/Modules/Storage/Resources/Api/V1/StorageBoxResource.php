<?php

namespace App\Modules\Storage\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StorageBoxResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'warehouse_id' => $this->warehouse_id,
            'room_id' => $this->room_id,
            'code_human' => $this->code_human,
            'name' => $this->name,
            'barcode_value' => $this->barcode_value,
            'barcode_type' => $this->barcode_type,
            'status' => $this->status,
            'placement_note' => $this->placement_note,
            'is_active' => $this->is_active,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse?->id,
                'name' => $this->warehouse?->name,
                'code' => $this->warehouse?->code,
            ]),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => $item->only(['id', 'sku', 'name', 'qty_on_hand', 'qty_reserved']))),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                ...$event->only(['id', 'type', 'created_at']), 'actor' => $event->actor?->only(['id', 'name']),
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'items' => route('api.v1.storage.items.index', ['box_id' => $this->id]),
            ],
        ];
    }
}
