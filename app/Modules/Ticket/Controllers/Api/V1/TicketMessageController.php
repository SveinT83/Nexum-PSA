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
    #[OA\Get(path: '/api/v1/tickets/{ticket}/messages', operationId: 'getTicketMessageMetadata', description: 'Metadata-only legacy projection. Use the separately scoped conversation/content endpoints for message bodies.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketLegacyMessageSummary')), new OA\Property(property: 'summary', ref: '#/components/schemas/TicketFirstResponseSummary'), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')], required: ['data', 'meta'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function index(
        Request $request,
        Ticket $ticket,
        TicketMessageIndexQuery $messages
    ) {
        app(\App\Modules\Ticket\Services\TicketMutationScopeGuard::class)->assertReadable($ticket);
        $validated = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $messages->paginate($ticket, (int) ($validated['per_page'] ?? 25));

        return TicketMessageSummaryResource::collection($paginator)
            ->additional(['summary' => $messages->summary($ticket)]);
    }
}
