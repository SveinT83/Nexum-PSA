<?php

namespace App\Modules\Workday\Resources\Api\V1;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'WorkdayRetentionPreview', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'evaluated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'work_retention_years', type: 'integer', enum: [3]),
        new OA\Property(property: 'absence_retention_years', type: 'integer', enum: [3]),
        new OA\Property(property: 'cleanup_enabled', type: 'boolean'),
        new OA\Property(property: 'eligible', type: 'object', description: 'Counts may overlap: one expired reminder can also have an expired notification copy.', properties: [
            new OA\Property(property: 'workdays', type: 'integer'),
            new OA\Property(property: 'absences', type: 'integer'),
            new OA\Property(property: 'reminders', type: 'integer'),
            new OA\Property(property: 'detached_receipts', type: 'integer'),
            new OA\Property(property: 'notification_copies', type: 'integer'),
            new OA\Property(property: 'diagnostic_copies', type: 'integer', description: 'Workday diagnostic entries and their request batch siblings have no retention purpose.'),
        ]),
        new OA\Property(property: 'untracked_notification_copies', type: 'integer', description: 'Legacy copies lacking provenance block restore readiness until their source is reconciled or expired and purged.'),
        new OA\Property(property: 'restore_ready', type: 'boolean', description: 'Application inventory only. Does not certify external backups, diagnostic archives or worker readiness.'),
    ]),
])]
final class RetentionOpenApi {}
