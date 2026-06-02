<?php

namespace App\Modules\Storage\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StorageWarehouseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'items_count' => $this->whenCounted('items'),
            'boxes_count' => $this->whenCounted('boxes'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'items' => route('api.v1.storage.items.index', ['warehouse_id' => $this->id]),
                'boxes' => route('api.v1.storage.boxes.index', ['warehouse_id' => $this->id]),
            ],
        ];
    }
}
