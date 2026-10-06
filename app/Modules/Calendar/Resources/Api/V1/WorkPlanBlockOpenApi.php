<?php

namespace App\Modules\Calendar\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Planning only: these records do not create absence, actual time, or queue-provider actions. */
#[OA\Schema(schema: 'WorkPlanBlockWrite', type: 'object', required: [
    'request_id', 'title', 'activity', 'timezone', 'starts_at', 'ends_at',
    'phone_duty_available', 'blocks_booking', 'recurrence_frequency',
], properties: [
    new OA\Property(property: 'request_id', description: 'Idempotent create UUID. Same UUID with different content returns 409.', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string', maxLength: 120),
    new OA\Property(property: 'activity', type: 'string', enum: ['education', 'work', 'other']),
    new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Oslo'),
    new OA\Property(property: 'starts_at', description: 'Local clock YYYY-MM-DDTHH:mm. Gaps and folds are rejected.', type: 'string', example: '2026-10-05T08:00'),
    new OA\Property(property: 'ends_at', description: 'Local clock; after start, at most 24 hours.', type: 'string', example: '2026-10-05T16:00'),
    new OA\Property(property: 'phone_duty_available', type: 'boolean'),
    new OA\Property(property: 'blocks_booking', type: 'boolean'),
    new OA\Property(property: 'recurrence_frequency', type: 'string', enum: ['none', 'weekly']),
    new OA\Property(property: 'recurrence_ends_at', description: 'Required for weekly series; at most one year.', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'WorkPlanBlockControl', type: 'object', required: ['version', 'scope'], properties: [
    new OA\Property(property: 'version', type: 'integer', minimum: 1),
    new OA\Property(property: 'scope', type: 'string', enum: ['event', 'series']),
    new OA\Property(property: 'occurrence_starts_at', description: 'Required for one occurrence in a series. Use its original instant including numeric offset.', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'WorkPlanBlockRead', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'activity', type: 'string'),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'timezone', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['confirmed', 'cancelled']),
    new OA\Property(property: 'phone_duty_available', type: 'boolean'),
    new OA\Property(property: 'blocks_booking', type: 'boolean'),
    new OA\Property(property: 'version', type: 'integer'),
    new OA\Property(property: 'recurrence_frequency', type: 'string', enum: ['none', 'weekly']),
    new OA\Property(property: 'recurrence_ends_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'WorkPlanBlockList', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkPlanBlockRead')),
    new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'current_page', type: 'integer'),
    new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer', enum: [30]),
    new OA\Property(property: 'next_page_url', type: 'string', nullable: true),
])]
final class WorkPlanBlockOpenApi {}
