<?php

namespace App\Modules\Workday\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Date-entry context is read-only; actual work is present only in the saved day snapshot. */
#[OA\Schema(schema: 'WorkdayEntryRange', type: 'object', required: ['start', 'end'], additionalProperties: false, properties: [
    new OA\Property(property: 'start', type: 'string', description: 'ISO timestamp with explicit offset or Z; whole minutes.', example: '2026-10-05T09:00+02:00'),
    new OA\Property(property: 'end', type: 'string', description: 'Exclusive end, ISO timestamp with explicit offset or Z.', example: '2026-10-05T10:00+02:00'),
])]
#[OA\Schema(schema: 'WorkdayEntry', type: 'object', additionalProperties: false,
    required: ['work_date', 'timezone', 'version', 'day', 'can_edit', 'requires_correction', 'plan_state', 'planned_minutes', 'planned_intervals', 'available_intervals', 'suggested_interval', 'reserved_intervals'],
    properties: [
        new OA\Property(property: 'work_date', type: 'string', format: 'date'),
        new OA\Property(property: 'timezone', type: 'string', description: 'Saved day timezone, or the employee work-plan timezone before first save.'),
        new OA\Property(property: 'version', type: 'integer', minimum: 0, description: '0 before first save; otherwise the saved day version for optimistic writes.'),
        new OA\Property(property: 'day', nullable: true, allOf: [new OA\Schema(ref: '#/components/schemas/Workday')], description: 'Persisted own day, or null. Opening an unregistered date creates nothing.'),
        new OA\Property(property: 'can_edit', type: 'boolean', description: 'True only for an unconfirmed date with current manage permission and workdays.write token ability; save rechecks all constraints.'),
        new OA\Property(property: 'requires_correction', type: 'boolean', description: 'Confirmed days require the existing correction operation before editing.'),
        new OA\Property(property: 'plan_state', type: 'string', enum: ['known', 'empty', 'unknown']),
        new OA\Property(property: 'planned_minutes', type: 'integer', minimum: 0, description: 'Effective plan after dated availability and explicit absence. Never actual or confirmed minutes.'),
        new OA\Property(property: 'planned_intervals', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkdayEntryRange'), description: 'Effective planned ranges in UTC, after explicit absence.'),
        new OA\Property(property: 'available_intervals', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkdayEntryRange'), description: 'Planned ranges minus current saved work and adjacent retained reservations. Not permission to save or an exhaustive list of allowed work times.'),
        new OA\Property(property: 'suggested_interval', nullable: true, allOf: [new OA\Schema(ref: '#/components/schemas/WorkdayEntryRange')], description: 'First available period up to 60 minutes, in record-local time with offset. Null when not editable, fully recorded, no effective plan, or the interval limit is reached. Saving still requires explicit actual time.'),
        new OA\Property(property: 'reserved_intervals', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkdayEntryRange'), description: 'Merged own current/confirmed intervals from retained neighboring work dates within four dates either side. UTC boundaries only, no descriptions or foreign work.'),
    ])]
#[OA\Schema(schema: 'WorkdayEntryResponse', type: 'object', required: ['data'], properties: [
    new OA\Property(property: 'data', ref: '#/components/schemas/WorkdayEntry'),
])]
final class WorkdayEntryOpenApi {}
