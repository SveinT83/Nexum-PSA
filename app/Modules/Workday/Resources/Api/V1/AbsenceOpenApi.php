<?php

namespace App\Modules\Workday\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Administrative absence only; the separate shared Calendar schema remains neutral. */
#[OA\Schema(schema: 'AbsenceInput', type: 'object', additionalProperties: false, required: ['version', 'category', 'mode', 'timezone'], properties: [
    new OA\Property(property: 'version', type: 'integer', minimum: 0, description: '0 at creation; otherwise latest version from readback.'),
    new OA\Property(property: 'category', type: 'string', enum: ['sickness', 'agreed_holiday', 'agreed_time_off', 'other']),
    new OA\Property(property: 'mode', type: 'string', enum: ['full_day', 'partial']),
    new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Oslo', description: 'IANA timezone; immutable after creation.'),
    new OA\Property(property: 'start_date', type: 'string', format: 'date', description: 'Required for full_day, prohibited for partial. First calendar day in record timezone.'),
    new OA\Property(property: 'end_date', type: 'string', format: 'date', description: 'Required for full_day, prohibited for partial. Last day included.'),
    new OA\Property(property: 'starts_at', type: 'string', example: '2026-10-01T10:00+02:00', description: 'Required for partial, prohibited for full_day. Minute-precise local ISO time or offset/Z. Ambiguous local time needs an offset.'),
    new OA\Property(property: 'ends_at', type: 'string', example: '2026-10-01T12:00+02:00', description: 'Required for partial, prohibited for full_day. End excluded; at most 366 elapsed days.'),
])]
#[OA\Schema(schema: 'AbsenceRange', type: 'object', properties: [
    new OA\Property(property: 'start', type: 'string', format: 'date-time'), new OA\Property(property: 'end', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'AbsenceSnapshot', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'version', type: 'integer'),
    new OA\Property(property: 'category', type: 'string', enum: ['sickness', 'agreed_holiday', 'agreed_time_off', 'other']),
    new OA\Property(property: 'mode', type: 'string', enum: ['full_day', 'partial']), new OA\Property(property: 'timezone', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'cancelled']),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'), new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'start_date', type: 'string', format: 'date'), new OA\Property(property: 'end_date', type: 'string', format: 'date'),
    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', description: 'Fixed original three-year retention deadline, also for revisions and receipts.'),
])]
#[OA\Schema(schema: 'Absence', allOf: [new OA\Schema(ref: '#/components/schemas/AbsenceSnapshot')], properties: [
    new OA\Property(property: 'calendar_event_id', type: 'integer'),
    new OA\Property(property: 'plan_impact', type: 'object', description: 'Present on show/mutations, computed from current effective-dated plan. Historical revisions preserve source facts, not dynamic plan calculations.', properties: [
        new OA\Property(property: 'planned_minutes', type: 'integer'), new OA\Property(property: 'affected_minutes', type: 'integer'),
        new OA\Property(property: 'affected_intervals', type: 'array', items: new OA\Items(ref: '#/components/schemas/AbsenceRange')),
        new OA\Property(property: 'remaining_intervals', type: 'array', items: new OA\Items(ref: '#/components/schemas/AbsenceRange')),
        new OA\Property(property: 'unknown_dates', type: 'array', items: new OA\Items(type: 'string', format: 'date'), description: 'No standard hours assumed; planned_minutes may be incomplete.'),
    ]),
    new OA\Property(property: 'has_work_conflicts', type: 'boolean', description: 'Present on show/mutations. Actual work is not adjusted.'),
    new OA\Property(property: 'work_conflicts', type: 'array', description: 'Present on show/mutations; details additionally require workday.view_own.', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'workday_id', type: 'string', format: 'uuid'), new OA\Property(property: 'work_date', type: 'string', format: 'date'),
        new OA\Property(property: 'version', type: 'integer'), new OA\Property(property: 'state', type: 'string', enum: ['draft', 'confirmed']),
        new OA\Property(property: 'overlap_minutes', type: 'integer'),
    ])),
])]
#[OA\Schema(schema: 'AbsenceResponse', type: 'object', properties: [
    new OA\Property(property: 'data', ref: '#/components/schemas/Absence'),
])]
#[OA\Schema(schema: 'AbsenceList', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Absence')),
    new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'current_page', type: 'integer'),
    new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'),
    new OA\Property(property: 'next_page_url', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'AbsenceHistory', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'version', type: 'integer'), new OA\Property(property: 'snapshot', ref: '#/components/schemas/AbsenceSnapshot'),
        new OA\Property(property: 'origin', type: 'string', enum: ['ui', 'api']), new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ])),
    new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'current_page', type: 'integer'),
    new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'),
    new OA\Property(property: 'next_page_url', type: 'string', nullable: true),
])]
final class AbsenceOpenApi {}
