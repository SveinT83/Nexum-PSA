<?php

namespace App\Http\Resources\Api\V1\Clients;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
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
            'name' => $this->name,
            'client_number' => $this->client_number,
            'org_no' => $this->org_no,
            'billing_email' => $this->billing_email,
            'active' => (bool) $this->active,
            'risk_score' => $this->risk_score,
            'links' => [
                'self' => route('api.v1.clients.show', $this->id),
                'assets' => route('api.v1.clients.assets', $this->id),
            ]
        ];
    }
}
