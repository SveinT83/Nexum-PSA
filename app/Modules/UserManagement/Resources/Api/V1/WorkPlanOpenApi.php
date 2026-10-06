<?php

namespace App\Modules\UserManagement\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Employee planning contract. Every route remains behind the Workday runtime switch. */
#[OA\Schema(schema: 'WorkPlanDay', type: 'object', additionalProperties: false, required: ['enabled', 'start', 'end'], properties: [
    new OA\Property(property: 'enabled', type: 'boolean'),
    new OA\Property(property: 'start', type: 'string', pattern: '^[0-9]{2}:[0-9]{2}$'),
    new OA\Property(property: 'end', description: 'Earlier than start means the following day; equal times are invalid.', type: 'string'),
])]
#[OA\Schema(schema: 'WorkPlanWeek', additionalProperties: false, type: 'object', required: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], properties: [
    new OA\Property(property: 'monday', ref: '#/components/schemas/WorkPlanDay'),
    new OA\Property(property: 'tuesday', ref: '#/components/schemas/WorkPlanDay'),
    new OA\Property(property: 'wednesday', ref: '#/components/schemas/WorkPlanDay'),
    new OA\Property(property: 'thursday', ref: '#/components/schemas/WorkPlanDay'),
    new OA\Property(property: 'friday', ref: '#/components/schemas/WorkPlanDay'),
    new OA\Property(property: 'saturday', ref: '#/components/schemas/WorkPlanDay'),
    new OA\Property(property: 'sunday', ref: '#/components/schemas/WorkPlanDay'),
])]
#[OA\Schema(schema: 'WorkPlanWrite', type: 'object', additionalProperties: false, required: ['revision', 'timezone', 'working_hours'], properties: [
    new OA\Property(property: 'revision', description: 'Opaque revision from the latest GET. Stale writes return 409.', type: 'string', minLength: 64, maxLength: 64),
    new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Oslo'),
    new OA\Property(property: 'accept_calendar_conflicts', description: 'Required true when the preview contains unowned Calendar rules. Those rules remain in force.', type: 'boolean'),
    new OA\Property(property: 'working_hours', ref: '#/components/schemas/WorkPlanWeek'),
])]
#[OA\Schema(schema: 'WorkPlanRead', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'revision', type: 'string', minLength: 64, maxLength: 64),
        new OA\Property(property: 'timezone', type: 'string'),
        new OA\Property(property: 'working_hours', ref: '#/components/schemas/WorkPlanWeek'),
        new OA\Property(property: 'origin', type: 'string', enum: ['profile', 'legacy_preferences', 'default']),
        new OA\Property(property: 'calendar_timezone', type: 'string', nullable: true),
        new OA\Property(property: 'calendar_conflicts', type: 'array', items: new OA\Items(type: 'object')),
    ]),
])]
final class WorkPlanOpenApi {}
