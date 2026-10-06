<?php

namespace App\Modules\Report\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Shared documentation schemas for the existing minimized worklog projections. */
#[OA\Schema(schema: 'WorklogTimeEntry', type: 'object', required: ['entry_alias', 'record_alias', 'technician_alias', 'client_alias', 'work_context_alias', 'source', 'registration_basis', 'work_date', 'minutes', 'billable'], properties: [
    new OA\Property(property: 'entry_alias', description: 'Workload- and source-scoped stable alias; deduplication key within this workload and installation key.', type: 'string'),
    new OA\Property(property: 'record_alias', type: 'string'),
    new OA\Property(property: 'technician_alias', type: 'string', nullable: true),
    new OA\Property(property: 'client_alias', description: 'Opaque alias, not a Commercial client_id. No identity lookup is provided.', type: 'string', nullable: true),
    new OA\Property(property: 'work_context_alias', type: 'string', nullable: true),
    new OA\Property(property: 'source', type: 'string', enum: ['ticket', 'task']),
    new OA\Property(property: 'registration_basis', description: 'Estimated Task completion time is explicitly labelled; recorded does not certify measured or payroll-approved time.', type: 'string', enum: ['recorded', 'estimated', 'unknown']),
    new OA\Property(property: 'work_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'minutes', description: 'Stored time, not a rounded billing projection or approved payroll time.', type: 'integer'),
    new OA\Property(property: 'billable', description: 'Stored source flag; not proof of invoicing, timebank consumption or chargeable amount.', type: 'boolean'),
])]
#[OA\Schema(schema: 'WorklogTechnician', type: 'object', required: ['technician_alias', 'total_minutes', 'billable_minutes', 'entry_count', 'active_days'], properties: [
    new OA\Property(property: 'technician_alias', type: 'string'),
    new OA\Property(property: 'total_minutes', type: 'integer'),
    new OA\Property(property: 'billable_minutes', type: 'integer'),
    new OA\Property(property: 'entry_count', type: 'integer'),
    new OA\Property(property: 'active_days', description: 'Distinct work dates for this technician. Do not sum overlapping periods.', type: 'integer'),
])]
#[OA\Schema(schema: 'WorklogWindowMeta', type: 'object', required: ['profile', 'date_from', 'date_to', 'total', 'available_total', 'returned_count', 'truncated', 'recovery', 'maximum_query_days', 'maximum_page_size', 'maximum_results'], properties: [
    new OA\Property(property: 'profile', type: 'string', enum: ['pseudonymized']),
    new OA\Property(property: 'date_from', type: 'string', format: 'date'),
    new OA\Property(property: 'date_to', type: 'string', format: 'date'),
    new OA\Property(property: 'total', description: 'All matching entries (time-entries) or non-null technicians (technicians), before result capping.', type: 'integer', minimum: 0),
    new OA\Property(property: 'available_total', description: 'min(total, maximum_results); ceiling for this date window across all pages.', type: 'integer', minimum: 0),
    new OA\Property(property: 'returned_count', type: 'integer', minimum: 0),
    new OA\Property(property: 'truncated', description: 'True if policy hides matching rows. An empty next page does not make the window complete.', type: 'boolean'),
    new OA\Property(property: 'recovery', type: 'string', nullable: true, enum: ['split_date_range', 'policy_limit_requires_review']),
    new OA\Property(property: 'maximum_query_days', type: 'integer', minimum: 1),
    new OA\Property(property: 'maximum_page_size', type: 'integer', minimum: 1),
    new OA\Property(property: 'maximum_results', type: 'integer', minimum: 1),
])]
#[OA\Schema(schema: 'WorklogPageMeta', allOf: [
    new OA\Schema(ref: '#/components/schemas/WorklogWindowMeta'),
    new OA\Schema(type: 'object', required: ['page', 'per_page', 'last_page', 'next_page'], properties: [
        new OA\Property(property: 'page', type: 'integer', minimum: 1),
        new OA\Property(property: 'per_page', type: 'integer', minimum: 1),
        new OA\Property(property: 'last_page', description: 'Pages of available_total, with minimum 1 even for an empty window.', type: 'integer', minimum: 1),
        new OA\Property(property: 'next_page', description: 'Next page within the capped window, otherwise null. Still inspect truncated.', type: 'integer', nullable: true),
    ]),
])]
#[OA\Schema(schema: 'WorklogTimeEntriesResponse', type: 'object', required: ['data', 'meta'], properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorklogTimeEntry')),
    new OA\Property(property: 'meta', ref: '#/components/schemas/WorklogPageMeta'),
])]
#[OA\Schema(schema: 'WorklogTechniciansResponse', type: 'object', required: ['data', 'meta'], properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorklogTechnician')),
    new OA\Property(property: 'meta', ref: '#/components/schemas/WorklogWindowMeta'),
])]
#[OA\Schema(schema: 'WorklogAccessDenied', type: 'object', required: ['message'], properties: [
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'reason_code', description: 'Present for coordinator middleware denials; exact codes are listed in the Knowledge contract.', type: 'string'),
    new OA\Property(property: 'request_id', description: 'Metadata-only audit correlation identifier for middleware denials.', type: 'string', format: 'uuid'),
])]
final class WorklogOpenApi {}
