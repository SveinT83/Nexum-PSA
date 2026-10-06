<?php

namespace App\Modules\Workday\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Source discovery is permission-bound evidence, never a second actual-time total. */
#[OA\Schema(schema: 'WorkdayAllocationInput', type: 'object', additionalProperties: false, required: ['source_key', 'source_revision', 'kind', 'minutes'], properties: [
    new OA\Property(property: 'source_key', type: 'string', maxLength: 100, description: 'Stable identity returned by source discovery.'),
    new OA\Property(property: 'source_revision', type: 'string', pattern: '^[a-f0-9]{64}$', description: 'Exact source content fingerprint. Changes require a new explicit selection.'),
    new OA\Property(property: 'kind', type: 'string', enum: ['task', 'ticket', 'calendar']),
    new OA\Property(property: 'calendar_id', type: 'integer', nullable: true, minimum: 1, description: 'Required for Calendar evidence; null for Task/Ticket.'),
    new OA\Property(property: 'minutes', type: 'integer', minimum: 1, maximum: 1440),
    new OA\Property(property: 'start', type: 'string', nullable: true, description: 'Optional employee placement, with end. Same local/offset and DST rules as actual intervals.'),
    new OA\Property(property: 'end', type: 'string', nullable: true),
    new OA\Property(property: 'acknowledged', type: 'boolean', description: 'Must be true for planned, estimated or unknown evidence. Does not change its original basis.'),
])]
#[OA\Schema(schema: 'WorkdayAllocation', type: 'object', properties: [
    new OA\Property(property: 'source_key', type: 'string', maxLength: 100, description: 'Stable identity returned by source discovery.'),
    new OA\Property(property: 'source_revision', type: 'string', pattern: '^[a-f0-9]{64}$', description: 'Exact source content fingerprint. Changes require a new explicit selection.'),
    new OA\Property(property: 'kind', type: 'string', enum: ['task', 'ticket', 'calendar']),
    new OA\Property(property: 'calendar_id', type: 'integer', nullable: true, minimum: 1, description: 'Required for Calendar evidence; null for Task/Ticket.'),
    new OA\Property(property: 'minutes', type: 'integer', minimum: 1, maximum: 1440),
    new OA\Property(property: 'start', type: 'string', nullable: true, description: 'Optional employee placement, with end. Same local/offset and DST rules as actual intervals.'),
    new OA\Property(property: 'end', type: 'string', nullable: true),
    new OA\Property(property: 'acknowledged', type: 'boolean', description: 'Must be true for planned, estimated or unknown evidence. Does not change its original basis.'),

    new OA\Property(property: 'basis', type: 'string', enum: ['recorded', 'estimated', 'unknown', 'planned']),
    new OA\Property(property: 'source_date', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'WorkdayAllocationsInput', type: 'object', additionalProperties: false, required: ['version', 'allocations'], properties: [
    new OA\Property(property: 'version', type: 'integer', minimum: 1),
    new OA\Property(property: 'allocations', type: 'array', maxItems: 100, items: new OA\Items(ref: '#/components/schemas/WorkdayAllocationInput'),
        description: 'Complete replacement. Empty array clears. Split by repeating a source with non-overlapping placements; all allocations together remain within actual minutes and remaining source capacity.'),
])]
#[OA\Schema(schema: 'WorkdayReconciliation', type: 'object', properties: [
    new OA\Property(property: 'needs_reconciliation', type: 'boolean'),
    new OA\Property(property: 'sources', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'index', type: 'integer', description: 'Zero-based allocation index.'),
        new OA\Property(property: 'status', type: 'string', enum: ['current', 'stale', 'unavailable']),
    ])),
])]
#[OA\Schema(schema: 'WorkdaySource', type: 'object', properties: [
    new OA\Property(property: 'source_key', type: 'string'),
    new OA\Property(property: 'source_revision', type: 'string'),
    new OA\Property(property: 'kind', type: 'string', enum: ['task', 'ticket', 'calendar']),
    new OA\Property(property: 'basis', type: 'string', enum: ['recorded', 'estimated', 'unknown', 'planned']),
    new OA\Property(property: 'minutes', type: 'integer'),
    new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'start', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'end', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'calendar_id', type: 'integer', nullable: true),
    new OA\Property(property: 'title', type: 'string', description: 'Read live under source permissions; not copied into Workday history.'),
    new OA\Property(property: 'url', type: 'string', format: 'uri'),
])]
#[OA\Schema(schema: 'WorkdaySourcesResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkdaySource')),
    new OA\Property(property: 'meta', type: 'object', properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['task', 'ticket', 'calendar']),
        new OA\Property(property: 'page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'),
        new OA\Property(property: 'next_page', type: 'integer', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['complete', 'partial', 'unavailable']),
        new OA\Property(property: 'total', type: 'integer', nullable: true, description: 'Unknown when unavailable; Calendar total is the observed count and may be a lower bound when truncated.'),
        new OA\Property(property: 'truncated', type: 'boolean'),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ]),
    new OA\Property(property: 'calendars', type: 'array', description: 'Present when choosing a Calendar. At most 100 permitted calendars.', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'name', type: 'string'),
    ])),
])]
final class SourceOpenApi {}
