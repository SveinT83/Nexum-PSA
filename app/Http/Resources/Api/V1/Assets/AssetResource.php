<?php

namespace App\Http\Resources\Api\V1\Assets;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'site_id' => $this->site_id,
            'user_id' => $this->user_id,
            'vendor_id' => $this->vendor_id,
            'name' => $this->name,
            'type' => $this->type,
            'vendor' => $this->vendor,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'mac_address' => $this->mac_address,
            'ip_address' => $this->ip_address,
            'ip_type' => $this->ip_type,
            'hostname' => $this->hostname,
            'source' => $this->source,
            'rmm_id' => $this->rmm_id,
            'is_managed' => (bool) $this->is_managed,
            'status' => $this->status,
            'last_seen_at' => $this->last_seen_at,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.assets.show', $this->id),
                'client' => route('api.v1.clients.show', $this->client_id),
            ]
        ];
    }
}
