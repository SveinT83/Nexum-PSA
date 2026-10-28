<?php

namespace App\Modules\Integration\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** A scoped administrative receipt contains identities and fingerprints, never original content. */
#[OA\Schema(schema: 'DomainApiAuditReadback', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'event', type: 'string'),
    new OA\Property(property: 'causer_id', type: 'integer', nullable: true), new OA\Property(property: 'subject_type', type: 'string', nullable: true),
    new OA\Property(property: 'subject_id', type: 'integer', nullable: true), new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'properties', type: 'object', properties: [
        new OA\Property(property: 'domain', type: 'string'), new OA\Property(property: 'changed_fields', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'before_fingerprint', type: 'string'), new OA\Property(property: 'after_fingerprint', type: 'string'),
        new OA\Property(property: 'context', type: 'object', properties: [new OA\Property(property: 'template_group_id', type: 'integer'), new OA\Property(property: 'schedule_id', type: 'integer'), new OA\Property(property: 'profile_user_id', type: 'integer'), new OA\Property(property: 'idempotency_key_hash', type: 'string'), new OA\Property(property: 'request_fingerprint', type: 'string')]),
    ]),
])]
class DomainApiAuditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'event' => $this->event, 'causer_id' => $this->causer_id,
            'subject_type' => $this->subject_type, 'subject_id' => $this->subject_id, 'created_at' => $this->created_at?->toISOString(),
            'properties' => Arr::only($this->properties?->toArray() ?? [], ['domain', 'changed_fields', 'before_fingerprint', 'after_fingerprint', 'context']),
        ];
    }
}
