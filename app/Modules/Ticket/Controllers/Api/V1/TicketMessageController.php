<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Queries\TicketMessageIndexQuery;
use App\Modules\Ticket\Resources\Api\V1\TicketMessageSummaryResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TicketMessageController extends Controller
{
    #[OA\Get(
        path: '/api/v1/tickets/{ticket}/messages',
        operationId: 'getTicketMessageMetadata',
        description: 'Returns paginated Ticket message metadata and first-response verification without message content.',
        summary: 'Get Ticket message metadata',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [
            new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 25, maximum: 100, minimum: 1)
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated message metadata and first-response summary'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing tickets.read scope'),
            new OA\Response(response: 404, description: 'Ticket not found'),
            new OA\Response(response: 422, description: 'Invalid pagination request'),
        ]
    )]
    public function index(
        Request $request,
        Ticket $ticket,
        TicketMessageIndexQuery $messages
    ) {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $messages->paginate($ticket, (int) ($validated['per_page'] ?? 25));

        return TicketMessageSummaryResource::collection($paginator)
            ->additional(['summary' => $messages->summary($ticket)]);
    }
}
