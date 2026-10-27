<?php
namespace App\Modules\Integration\Support;
use OpenApi\Attributes as OA;
/** Bounded promoted envelopes; definitions are identical to authoritative Dev. */
#[OA\Schema(schema: 'ParityPageMeta', type: 'object', required: ['current_page', 'last_page', 'per_page', 'total'], properties: [
    new OA\Property(property: 'current_page', type: 'integer', minimum: 1), new OA\Property(property: 'last_page', type: 'integer', minimum: 1),
    new OA\Property(property: 'per_page', type: 'integer', minimum: 1, maximum: 100), new OA\Property(property: 'total', type: 'integer', minimum: 0),
    new OA\Property(property: 'from', type: 'integer', nullable: true), new OA\Property(property: 'to', type: 'integer', nullable: true),
    new OA\Property(property: 'path', type: 'string'), new OA\Property(property: 'links', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'ParityPageLinks', type: 'object', properties: [
    new OA\Property(property: 'first', type: 'string'), new OA\Property(property: 'last', type: 'string'),
    new OA\Property(property: 'prev', type: 'string', nullable: true), new OA\Property(property: 'next', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'ParityReadError', type: 'object', required: ['message'], properties: [
    new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'errors', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))),
])]
final class DomainApiReadOpenApi {}
