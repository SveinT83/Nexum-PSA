<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Explicit preview and receipt contracts never expose internal retry hashes. */
#[OA\Schema(schema: 'TicketTimeDeletionPreview', type: 'object', required: ['ticket_id', 'time_entry_id', 'entry', 'allowed', 'blockers', 'preview_token', 'expires_in_seconds'], properties: [
    new OA\Property(property: 'ticket_id', type: 'integer'),
    new OA\Property(property: 'time_entry_id', type: 'integer'),
    new OA\Property(property: 'entry', ref: '#/components/schemas/TicketTimeEntryReadback'),
    new OA\Property(property: 'allowed', type: 'boolean'),
    new OA\Property(property: 'blockers', type: 'array', items: new OA\Items(type: 'string', enum: ['workflow_blocked', 'not_direct_manual_time', 'time_already_processed', 'financial_allocation_exists', 'financial_order_exists', 'retained_workday_reference'])),
    new OA\Property(property: 'preview_token', type: 'string', nullable: true),
    new OA\Property(property: 'expires_in_seconds', type: 'integer', example: 600),
])]
#[OA\Schema(schema: 'TicketTimeDeletionRequest', type: 'object', required: ['reason', 'preview_token', 'idempotency_key'], additionalProperties: false, properties: [
    new OA\Property(property: 'reason', type: 'string', minLength: 1, maxLength: 2000),
    new OA\Property(property: 'preview_token', type: 'string', maxLength: 8192),
    new OA\Property(property: 'idempotency_key', type: 'string', minLength: 8, maxLength: 100, pattern: '^[A-Za-z0-9_.:-]{8,100}$'),
])]
#[OA\Schema(schema: 'TicketTimeDeletionReceipt', type: 'object', required: ['deleted', 'ticket_id', 'time_entry_id', 'audit_event_id', 'actor_id', 'reason', 'deleted_at', 'receipt_url'], properties: [
    new OA\Property(property: 'deleted', type: 'boolean', enum: [true]),
    new OA\Property(property: 'ticket_id', type: 'integer'),
    new OA\Property(property: 'time_entry_id', type: 'integer'),
    new OA\Property(property: 'audit_event_id', type: 'integer'),
    new OA\Property(property: 'actor_id', type: 'integer'),
    new OA\Property(property: 'reason', type: 'string'),
    new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'receipt_url', type: 'string', format: 'uri'),
])]
final class TicketTimeDeletionOpenApi {}
