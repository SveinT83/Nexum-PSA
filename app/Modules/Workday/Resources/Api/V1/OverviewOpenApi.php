<?php

namespace App\Modules\Workday\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Deliberately separate from owner responses: no current revision, absence or private correction metadata. */
#[OA\Schema(schema: 'WorkdayOverviewAllocation', type: 'object', properties: [
    new OA\Property(property: 'kind', type: 'string', enum: ['task', 'ticket', 'calendar']),
    new OA\Property(property: 'basis', type: 'string', enum: ['recorded', 'estimated', 'unknown', 'planned']),
    new OA\Property(property: 'minutes', type: 'integer'),
    new OA\Property(property: 'start', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'end', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'acknowledged', type: 'boolean'),
    new OA\Property(property: 'source', type: 'object', properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['current', 'stale', 'unavailable', 'not_loaded'], description: 'List omits source reads. Detail/history recheck viewer source grants and token scopes.'),
        new OA\Property(property: 'title', type: 'string', nullable: true), new OA\Property(property: 'url', type: 'string', format: 'uri', nullable: true),
    ]),
])]
#[OA\Schema(schema: 'WorkdayOverviewSnapshot', type: 'object', properties: [
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'actual_minutes', type: 'number'), new OA\Property(property: 'gross_minutes', type: 'number'),
    new OA\Property(property: 'excluded_break_minutes', type: 'integer'), new OA\Property(property: 'included_break_minutes', type: 'integer'),
    new OA\Property(property: 'allocated_minutes', type: 'integer'), new OA\Property(property: 'unallocated_minutes', type: 'number'),
    new OA\Property(property: 'intervals', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'start', type: 'string', format: 'date-time'), new OA\Property(property: 'end', type: 'string', format: 'date-time'),
        new OA\Property(property: 'minutes', type: 'integer'), new OA\Property(property: 'description', type: 'string'),
    ])),
    new OA\Property(property: 'breaks', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'start', type: 'string', format: 'date-time'), new OA\Property(property: 'end', type: 'string', format: 'date-time'),
        new OA\Property(property: 'minutes', type: 'integer'), new OA\Property(property: 'included', type: 'boolean'),
    ])),
    new OA\Property(property: 'allocations', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkdayOverviewAllocation')),
])]
#[OA\Schema(schema: 'WorkdayOverviewDay', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'worker', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'name', type: 'string')]),
    new OA\Property(property: 'work_date', type: 'string', format: 'date'), new OA\Property(property: 'timezone', type: 'string'),
    new OA\Property(property: 'confirmed', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'version', type: 'integer'),
        new OA\Property(property: 'confirmed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'snapshot', ref: '#/components/schemas/WorkdayOverviewSnapshot'),
    ]),
])]
#[OA\Schema(schema: 'WorkdayOverviewPage', type: 'object', properties: [
    new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'returned_count', type: 'integer'),
    new OA\Property(property: 'page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'),
    new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'next_page', type: 'integer', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['complete', 'partial'], description: 'Complete only if this first page contains the full result. No silent result ceiling.'),
    new OA\Property(property: 'truncated', type: 'boolean', enum: [false]),
])]
#[OA\Schema(schema: 'WorkdayOverviewList', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkdayOverviewDay')),
    new OA\Property(property: 'meta', allOf: [new OA\Schema(ref: '#/components/schemas/WorkdayOverviewPage')], properties: [
        new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date'),
        new OA\Property(property: 'worker_id', type: 'integer', nullable: true), new OA\Property(property: 'worker', type: 'string'),
    ]),
    new OA\Property(property: 'totals', type: 'object', description: 'Full filtered confirmed set, including rows not on this page. Refresh between requests; pagination is not an immutable export.', properties: [
        new OA\Property(property: 'confirmed_days', type: 'integer'), new OA\Property(property: 'actual_minutes', type: 'number'),
        new OA\Property(property: 'allocated_minutes', type: 'integer'), new OA\Property(property: 'unallocated_minutes', type: 'number'),
        new OA\Property(property: 'scope', type: 'string', enum: ['all_matching_confirmed_days']),
    ]),
])]
#[OA\Schema(schema: 'WorkdayOverviewHistory', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkdayOverviewDay')),
    new OA\Property(property: 'meta', ref: '#/components/schemas/WorkdayOverviewPage'),
])]
final class OverviewOpenApi {}
