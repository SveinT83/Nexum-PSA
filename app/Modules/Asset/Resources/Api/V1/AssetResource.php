<?php

namespace App\Modules\Asset\Resources\Api\V1;

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
        // This resource mirrors the asset table plus navigational links. It
        // intentionally keeps `client_id`, `site_id`, and other foreign keys in
        // the payload because integrations often need stable IDs more than
        // expanded nested objects.
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'work_context_id' => $this->work_context_id,
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
            'rmm_links' => $this->rmmLinks->map(function($link) {
                return [
                    'integration_id' => $link->integration_id,
                    'external_id' => $link->external_id,
                ];
            }),
            'is_managed' => (bool) $this->is_managed,
            'status' => $this->status,
            'last_seen_at' => $this->last_seen_at,
            'metadata' => $this->metadata,
            'work_context' => $this->whenLoaded('workContext', fn () => [
                'id' => $this->workContext?->id,
                'type' => $this->workContext?->type,
                'name' => $this->workContext?->name,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.assets.show', $this->id),
                'client' => $this->client_id ? route('api.v1.clients.show', $this->client_id) : null,
            ]
        ];
    }
}
