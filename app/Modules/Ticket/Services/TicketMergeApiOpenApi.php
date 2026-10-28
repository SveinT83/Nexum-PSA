<?php

namespace App\Modules\Ticket\Services;

use OpenApi\Attributes as OA;

/** Explicit API payload shapes; variable registry values retain their declared JSON types. */
#[OA\Schema(schema: 'TicketMergeSuggestionReadback', type: 'object', properties: [new OA\Property(property: 'target_ticket_id', type: 'integer'), new OA\Property(property: 'source_ticket_ids', type: 'array', items: new OA\Items(type: 'integer')), new OA\Property(property: 'confidence', type: 'integer'), new OA\Property(property: 'reason', type: 'string'), new OA\Property(property: 'tickets', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'ticket_key', type: 'string'), new OA\Property(property: 'subject', type: 'string'), new OA\Property(property: 'client_id', type: 'integer', nullable: true)]))])]
#[OA\Schema(schema: 'TicketMergeReadback', type: 'object', properties: [new OA\Property(property: 'target_ticket_id', type: 'integer'), new OA\Property(property: 'target_ticket_key', type: 'string'), new OA\Property(property: 'source_ticket_ids', type: 'array', items: new OA\Items(type: 'integer')), new OA\Property(property: 'merged_count', type: 'integer'), new OA\Property(property: 'readback_url', type: 'string')])]
#[OA\Schema(schema: 'TicketMergeDismissalReadback', type: 'object', properties: [new OA\Property(property: 'ticket_ids', type: 'array', items: new OA\Items(type: 'integer')), new OA\Property(property: 'dismissed', type: 'boolean')])]
#[OA\Schema(schema: 'TicketMergeRequest', type: 'object', properties: [new OA\Property(property: 'target_ticket_id', type: 'integer'), new OA\Property(property: 'source_ticket_ids', type: 'array', items: new OA\Items(type: 'integer'), minItems: 1, maxItems: 100, uniqueItems: true), new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 2000), new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 191)], required: ['target_ticket_id', 'source_ticket_ids', 'idempotency_key'])]
#[OA\Schema(schema: 'TicketMergeDismissRequest', type: 'object', properties: [new OA\Property(property: 'ticket_ids', type: 'array', items: new OA\Items(type: 'integer'), minItems: 2, maxItems: 100, uniqueItems: true), new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 255)], required: ['ticket_ids'])]
final class TicketMergeApiOpenApi {}
