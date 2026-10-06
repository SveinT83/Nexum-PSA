<?php

namespace App\Modules\Workday\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Personal actual-time contract. Descriptions and revisions stay inside Workday retention. */
#[OA\Schema(schema: 'WorkdayIntervalInput', type: 'object', required: ['start', 'end'], additionalProperties: false, properties: [
    new OA\Property(property: 'start', type: 'string', description: 'Minute-precise local ISO date/time or RFC3339 with offset/Z. Ambiguous local times need an explicit offset.', example: '2026-10-01T08:00+02:00'),
    new OA\Property(property: 'end', type: 'string', example: '2026-10-01T16:00+02:00'),
    new OA\Property(property: 'description', type: 'string', maxLength: 1000),
])]
#[OA\Schema(schema: 'WorkdayBreakInput', type: 'object', required: ['start', 'end', 'included'], additionalProperties: false, properties: [
    new OA\Property(property: 'start', type: 'string'), new OA\Property(property: 'end', type: 'string'),
    new OA\Property(property: 'included', type: 'boolean', description: 'True counts the break as actual work; false subtracts its actual elapsed minutes.'),
])]
#[OA\Schema(schema: 'WorkdayDraftInput', type: 'object', required: ['version', 'timezone', 'description', 'intervals', 'breaks'], additionalProperties: false, properties: [
    new OA\Property(property: 'version', type: 'integer', minimum: 0, description: '0 for first creation, otherwise the latest day version. Stale versions return 409.'),
    new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Oslo', description: 'IANA timezone, immutable after first creation.'),
    new OA\Property(property: 'description', type: 'string', maxLength: 2000, example: 'Work - unspecified'),
    new OA\Property(property: 'intervals', type: 'array', minItems: 1, maxItems: 24, items: new OA\Items(ref: '#/components/schemas/WorkdayIntervalInput')),
    new OA\Property(property: 'breaks', type: 'array', maxItems: 48, items: new OA\Items(ref: '#/components/schemas/WorkdayBreakInput')),
    new OA\Property(property: 'allocations', type: 'array', maxItems: 100, items: new OA\Items(ref: '#/components/schemas/WorkdayAllocationInput'), description: 'Optional complete replacement; omit to preserve current selections, send [] to clear. All preserved selections are revalidated.'),
])]
#[OA\Schema(schema: 'WorkdayDurationInput', type: 'object', required: ['activity_id', 'hours'], additionalProperties: false, properties: [
    new OA\Property(property: 'activity_id', type: 'integer', minimum: 1),
    new OA\Property(property: 'project_id', type: 'integer', nullable: true),
    new OA\Property(property: 'hours', type: 'number', minimum: 0, maximum: 24, description: 'Hundredths of an hour. Zero removes this activity/project row.'),
    new OA\Property(property: 'comment', type: 'string', maxLength: 2000),
])]
#[OA\Schema(schema: 'WorkdaySaveInput', type: 'object', required: ['version', 'timezone', 'description'], additionalProperties: false, properties: [
    new OA\Property(property: 'version', type: 'integer', minimum: 0),
    new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Oslo'),
    new OA\Property(property: 'description', type: 'string', maxLength: 2000),
    new OA\Property(property: 'intervals', type: 'array', maxItems: 24, items: new OA\Items(ref: '#/components/schemas/WorkdayIntervalInput'), description: 'Clock mode: supply intervals and breaks. Empty intervals explicitly remove all time.'),
    new OA\Property(property: 'breaks', type: 'array', maxItems: 48, items: new OA\Items(ref: '#/components/schemas/WorkdayBreakInput')),
    new OA\Property(property: 'durations', type: 'array', maxItems: 100, items: new OA\Items(ref: '#/components/schemas/WorkdayDurationInput'), description: 'Duration mode: replaces the whole date. Requires an explicit employee mapping. Do not combine with clock intervals.'),
    new OA\Property(property: 'allocations', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkdayAllocationInput')),
])]
#[OA\Schema(schema: 'WorkdayVersionInput', type: 'object', required: ['version'], additionalProperties: false, properties: [
    new OA\Property(property: 'version', type: 'integer', minimum: 1),
])]
#[OA\Schema(schema: 'WorkdayConfirmInput', type: 'object', required: ['version', 'preview_token', 'confirmed'], additionalProperties: false, properties: [
    new OA\Property(property: 'version', type: 'integer', minimum: 1),
    new OA\Property(property: 'preview_token', type: 'string', format: 'uuid', description: 'Unexpired token from preview for this exact owner, day and revision.'),
    new OA\Property(property: 'confirmed', type: 'boolean', enum: [true], description: 'Explicit employee confirmation. Never infer confirmation from activity or draft save.'),
    new OA\Property(property: 'accept_absence_conflicts', type: 'boolean', enum: [true], description: 'Required when preview data.absence_warnings is nonempty. Review work and absence before acknowledging. An intervening absence change invalidates the preview.'),
])]
#[OA\Schema(schema: 'WorkdayCorrectionInput', type: 'object', required: ['version', 'reason'], additionalProperties: false, properties: [
    new OA\Property(property: 'version', type: 'integer', minimum: 1),
    new OA\Property(property: 'reason', type: 'string', minLength: 1, maxLength: 1000),
])]
#[OA\Schema(schema: 'WorkdaySnapshot', type: 'object', properties: [
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'intervals', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'start', type: 'string', format: 'date-time'), new OA\Property(property: 'end', type: 'string', format: 'date-time'),
        new OA\Property(property: 'minutes', type: 'integer'), new OA\Property(property: 'description', type: 'string'),
    ])),
    new OA\Property(property: 'breaks', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'start', type: 'string', format: 'date-time'), new OA\Property(property: 'end', type: 'string', format: 'date-time'),
        new OA\Property(property: 'minutes', type: 'integer'), new OA\Property(property: 'included', type: 'boolean'),
    ])),
    new OA\Property(property: 'durations', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'activity_id', type: 'integer'), new OA\Property(property: 'project_id', type: 'integer', nullable: true),
        new OA\Property(property: 'units', type: 'integer', description: 'Hundredths of an hour; multiply by 36 for seconds.'),
        new OA\Property(property: 'comment', type: 'string'),
    ])),
    new OA\Property(property: 'absence_overlap_acknowledged', type: 'boolean', description: 'Present on confirmed snapshots; true if overlapping absence was explicitly acknowledged.'),
    new OA\Property(property: 'allocations', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkdayAllocation')),
    new OA\Property(property: 'allocated_minutes', type: 'integer'), new OA\Property(property: 'unallocated_minutes', type: 'number'),
    new OA\Property(property: 'gross_minutes', type: 'number'), new OA\Property(property: 'actual_minutes', type: 'number'),
    new OA\Property(property: 'excluded_break_minutes', type: 'integer'), new OA\Property(property: 'included_break_minutes', type: 'integer'),
])]
#[OA\Schema(schema: 'WorkdayRevision', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'version', type: 'integer'),
    new OA\Property(property: 'state', type: 'string', enum: ['draft', 'confirmed', 'recorded']),
    new OA\Property(property: 'snapshot', ref: '#/components/schemas/WorkdaySnapshot'),
    new OA\Property(property: 'origin', type: 'string', enum: ['ui', 'api', 'sync']),
    new OA\Property(property: 'correction_reason', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'Workday', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'work_date', type: 'string', format: 'date'),
    new OA\Property(property: 'timezone', type: 'string'), new OA\Property(property: 'version', type: 'integer'),
    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'current', ref: '#/components/schemas/WorkdayRevision'),
    new OA\Property(property: 'reconciliation', ref: '#/components/schemas/WorkdayReconciliation'),
    new OA\Property(property: 'confirmed_reconciliation', nullable: true, allOf: [new OA\Schema(ref: '#/components/schemas/WorkdayReconciliation')]),
    new OA\Property(property: 'absence_warnings', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'absence_id', type: 'string', format: 'uuid'), new OA\Property(property: 'overlap_minutes', type: 'integer'), new OA\Property(property: 'message', type: 'string')]), description: 'Generic overlap warnings, without absence reasons.'),
    new OA\Property(property: 'confirmed', nullable: true, allOf: [new OA\Schema(ref: '#/components/schemas/WorkdayRevision')]),
])]
#[OA\Schema(schema: 'WorkdayResponse', type: 'object', properties: [
    new OA\Property(property: 'data', ref: '#/components/schemas/Workday'),
    new OA\Property(property: 'preview', description: 'Present only for preview operations; validity is at most 30 minutes.', type: 'object', properties: [
        new OA\Property(property: 'token', type: 'string', format: 'uuid'), new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
    ]),
])]
#[OA\Schema(schema: 'WorkdayList', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Workday')),
    new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'current_page', type: 'integer'),
    new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'),
    new OA\Property(property: 'next_page_url', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WorkdayHistory', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkdayRevision')),
    new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'current_page', type: 'integer'),
    new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'),
    new OA\Property(property: 'next_page_url', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WorkdaySettingsInput', type: 'object', additionalProperties: false, required: ['version', 'enabled'], properties: [
    new OA\Property(property: 'version', type: 'integer', minimum: 0), new OA\Property(property: 'enabled', type: 'boolean'),
])]
#[OA\Schema(schema: 'WorkdaySettingsResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'version', type: 'integer'), new OA\Property(property: 'enabled', type: 'boolean'),
        new OA\Property(property: 'effective_enabled', type: 'boolean'), new OA\Property(property: 'deployment_enabled', type: 'boolean'),
        new OA\Property(property: 'retention_years', type: 'integer', enum: [3]),
    ]),
])]
final class WorkdayOpenApi {}
