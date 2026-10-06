<?php

namespace App\Modules\Workday\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Explicit creation is separate from Workday confirmation and from billable Ticket time. */
#[OA\Schema(schema: 'WorkdayTaskConversionPreviewInput', type: 'object', additionalProperties: false,
    required: ['version', 'interval_index', 'title', 'description'], properties: [
        new OA\Property(property: 'version', type: 'integer', minimum: 1),
        new OA\Property(property: 'interval_index', type: 'integer', minimum: 0, maximum: 23, description: 'Zero-based saved work interval.'),
        new OA\Property(property: 'start', type: 'string', nullable: true, maxLength: 32, description: 'Optional selected start inside the interval. Same local/offset and DST rules as Workday. Defaults to the saved start.'),
        new OA\Property(property: 'end', type: 'string', nullable: true, maxLength: 32, description: 'Optional selected end inside the interval. Defaults to the saved end.'),
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', maxLength: 2000, description: 'Exact Task description and time note. No hidden source description is copied.'),
        new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 1000, description: 'Required when the current revision is confirmed.'),
    ])]
#[OA\Schema(schema: 'WorkdayTaskConversionCreateInput', type: 'object', additionalProperties: false,
    required: ['version', 'preview_token', 'create_task'], properties: [
        new OA\Property(property: 'version', type: 'integer', minimum: 1),
        new OA\Property(property: 'preview_token', type: 'string', format: 'uuid'),
        new OA\Property(property: 'create_task', type: 'boolean', enum: [true], description: 'Explicit employee instruction to create the reviewed Task and time. Does not confirm the day.'),
    ])]
#[OA\Schema(schema: 'WorkdayTaskConversionActivity', type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'interval_index', type: 'integer'),
    new OA\Property(property: 'start', type: 'string', format: 'date-time'), new OA\Property(property: 'end', type: 'string', format: 'date-time'),
    new OA\Property(property: 'minutes', type: 'integer'), new OA\Property(property: 'work_date', type: 'string', format: 'date'),
    new OA\Property(property: 'timezone', type: 'string'), new OA\Property(property: 'reason', type: 'string', nullable: true),
    new OA\Property(property: 'ranges', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'start', type: 'string', format: 'date-time'), new OA\Property(property: 'end', type: 'string', format: 'date-time'),
    ]), description: 'Exact work placements after excluded breaks; one Task time entry holds their summed minutes.'),
    new OA\Property(property: 'target', type: 'object', properties: [
        new OA\Property(property: 'work_context_id', type: 'integer'), new OA\Property(property: 'context_type', type: 'string', enum: ['internal']),
        new OA\Property(property: 'context_name', type: 'string'), new OA\Property(property: 'status_id', type: 'integer'), new OA\Property(property: 'status_name', type: 'string'),
        new OA\Property(property: 'owner_id', type: 'integer'), new OA\Property(property: 'assigned_to', type: 'integer'),
        new OA\Property(property: 'billable', type: 'boolean', enum: [false]), new OA\Property(property: 'visibility', type: 'string', enum: ['internal']),
    ]),
])]
#[OA\Schema(schema: 'WorkdayTaskConversionResponse', type: 'object', properties: [
    new OA\Property(property: 'data', ref: '#/components/schemas/Workday'),
    new OA\Property(property: 'state', type: 'string', enum: ['ready', 'stale', 'created'], description: 'Present on GET read-back. Ready means the saved version and expiry match; source and target access are rechecked on create.'),
    new OA\Property(property: 'preview', type: 'object', description: 'Present on preview and GET read-back.', properties: [
        new OA\Property(property: 'token', type: 'string', format: 'uuid'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'activity', ref: '#/components/schemas/WorkdayTaskConversionActivity'),
    ]),
    new OA\Property(property: 'conversion', type: 'object', nullable: true, description: 'Present on creation and GET read-back. Retained creation receipt; current Task is read via the existing Task API.', properties: [
        new OA\Property(property: 'task_id', type: 'integer'), new OA\Property(property: 'task_time_entry_id', type: 'integer'),
        new OA\Property(property: 'source_key', type: 'string'), new OA\Property(property: 'minutes', type: 'integer'),
        new OA\Property(property: 'billable', type: 'boolean', enum: [false]), new OA\Property(property: 'task_url', type: 'string', format: 'uri'),
    ]),
])]
final class TaskConversionOpenApi {}
