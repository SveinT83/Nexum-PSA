<?php

namespace App\Modules\Task\Services;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'TaskChecklistReadback', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'task_id', type: 'integer'),
    new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'is_checked', type: 'boolean'), new OA\Property(property: 'checked_by', type: 'integer', nullable: true),
    new OA\Property(property: 'checked_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'sort_order', type: 'integer'),
])]
#[OA\Schema(schema: 'TaskFieldSuggestions', type: 'object', description: 'Editable suggestions only; no Task, checklist, status, time or billing mutation is applied.', properties: [
    new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'ticket_id', type: 'integer'), new OA\Property(property: 'estimated_minutes', type: 'integer'),
    new OA\Property(property: 'ticket_rate_key', type: 'string'), new OA\Property(property: 'queue_id', type: 'integer'),
    new OA\Property(property: 'priority_id', type: 'integer'), new OA\Property(property: 'category_id', type: 'integer'),
    new OA\Property(property: 'assigned_to', type: 'integer'), new OA\Property(property: 'tag_names', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'checklist_items', type: 'array', items: new OA\Items(type: 'string')),
])]
final class TaskOperationOpenApi {}
