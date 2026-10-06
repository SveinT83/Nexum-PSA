<?php

namespace App\Modules\Workday\Resources\Api\V1;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'WorkdayReminder', type: 'object', required: ['id', 'work_date', 'generation', 'notification_id', 'url'],
    properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'work_date', type: 'string', format: 'date'),
        new OA\Property(property: 'generation', type: 'integer'),
        new OA\Property(property: 'notification_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'snoozed_until', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'url', type: 'string')])]
#[OA\Schema(schema: 'WorkdayReminderPreferences', type: 'object', additionalProperties: false,
    required: ['database_enabled', 'mail_enabled', 'web_push_enabled'],
    properties: [new OA\Property(property: 'database_enabled', type: 'boolean', default: true),
        new OA\Property(property: 'mail_enabled', type: 'boolean', default: false),
        new OA\Property(property: 'web_push_enabled', type: 'boolean', default: false)])]
#[OA\Schema(schema: 'WorkdayReminderPreferencesResponse', type: 'object', properties: [
    new OA\Property(property: 'data', ref: '#/components/schemas/WorkdayReminderPreferences'),
    new OA\Property(property: 'readiness', type: 'object', properties: [
        new OA\Property(property: 'database', type: 'boolean'), new OA\Property(property: 'mail', type: 'boolean'),
        new OA\Property(property: 'web_push', type: 'boolean')])])]
class ReminderOpenApi {}
