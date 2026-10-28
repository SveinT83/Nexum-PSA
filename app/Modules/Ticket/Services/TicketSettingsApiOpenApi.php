<?php

namespace App\Modules\Ticket\Services;

use OpenApi\Attributes as OA;

/** Fixed Ticket policy projections omit arbitrary CommonSetting values and all mailbox credentials. */
#[OA\Schema(schema: 'TicketSettingsReadback', type: 'object', properties: [
    new OA\Property(property: 'default_email_account_id', type: 'integer', nullable: true),
    new OA\Property(property: 'solution_policy', type: 'object', properties: [new OA\Property(property: 'allow_internal_solution_notes', type: 'boolean')]),
    new OA\Property(property: 'portal_policy', type: 'object', properties: [new OA\Property(property: 'default_customer_visibility', type: 'string', enum: ['published', 'unpublished'])]),
    new OA\Property(property: 'quote_cost_policy', type: 'object', properties: [new OA\Property(property: 'quote_required_cost_threshold', type: 'number', nullable: true)]),
    new OA\Property(property: 'merge_settings', type: 'object', properties: [new OA\Property(property: 'auto_merge_enabled', type: 'boolean'), new OA\Property(property: 'ai_merge_enabled', type: 'boolean'), new OA\Property(property: 'ai_similarity_threshold', type: 'integer')]),
])]
final class TicketSettingsApiOpenApi {}
