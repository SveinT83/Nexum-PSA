<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Resources\Api\V1\TicketHistoryApiResource;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Internal history content is separate from legacy read-only activity metadata. */
class TicketHistoryApiController extends Controller
{
    #[OA\Get(path: '/api/v1/tickets/{ticket}/history', operationId: 'ticketTicketHistoryApiControllerindex', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.activities.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketHistoryReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function index(Request $request, Ticket $ticket)
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);

        return TicketHistoryApiResource::collection($ticket->events()->orderByDesc('created_at')->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/history/{event}', operationId: 'ticketTicketHistoryApiControllershow', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.activities.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketHistoryReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(Request $request, Ticket $ticket, int $event)
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);

        return new TicketHistoryApiResource($ticket->events()->findOrFail($event));
    }
}
