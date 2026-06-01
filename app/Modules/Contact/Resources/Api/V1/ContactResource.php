<?php

namespace App\Modules\Contact\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primaryEmail = $this->emails->firstWhere('is_primary', true) ?? $this->emails->first();
        $primaryPhone = $this->phones->firstWhere('is_primary', true) ?? $this->phones->first();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'display_name' => $this->display_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'organization_name' => $this->organization_name,
            'job_title' => $this->job_title,
            'primary_email' => $primaryEmail?->email,
            'primary_phone' => $primaryPhone?->phone,
            'preferred_language' => $this->preferred_language,
            'communication_language' => $this->communication_language,
            'timezone' => $this->timezone,
            'do_not_call' => (bool) $this->do_not_call,
            'do_not_email' => (bool) $this->do_not_email,
            'marketing_consent' => (bool) $this->marketing_consent,
            'emails' => $this->whenLoaded('emails', fn () => $this->emails->map(fn ($email) => [
                'id' => $email->id,
                'label' => $email->label,
                'email' => $email->email,
                'is_primary' => (bool) $email->is_primary,
            ])->values()),
            'phones' => $this->whenLoaded('phones', fn () => $this->phones->map(fn ($phone) => [
                'id' => $phone->id,
                'label' => $phone->label,
                'phone' => $phone->phone,
                'is_primary' => (bool) $phone->is_primary,
            ])->values()),
            'relations' => $this->whenLoaded('relations', fn () => $this->relations->map(fn ($relation) => [
                'id' => $relation->id,
                'related_type' => $relation->related_type,
                'related_id' => $relation->related_id,
                'relation_type' => $relation->relation_type,
                'is_primary' => (bool) $relation->is_primary,
            ])->values()),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.contacts.show', $this->id),
            ],
        ];
    }
}
