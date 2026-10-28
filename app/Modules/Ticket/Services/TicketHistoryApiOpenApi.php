<?php

namespace App\Modules\Ticket\Services;

use OpenApi\Attributes as OA;

/** Explicit API payload shapes; variable registry values retain their declared JSON types. */
#[OA\Schema(schema: 'TicketHistoryReadback', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'ticket_id', type: 'integer'), new OA\Property(property: 'actor_id', type: 'integer', nullable: true), new OA\Property(property: 'type', type: 'string'), new OA\Property(property: 'message', type: 'string', nullable: true), new OA\Property(property: 'created_at', type: 'string', nullable: true), new OA\Property(property: 'automation_run_id', type: 'integer', nullable: true), new OA\Property(property: 'automation_run_url', type: 'string', nullable: true)])]
final class TicketHistoryApiOpenApi {}
