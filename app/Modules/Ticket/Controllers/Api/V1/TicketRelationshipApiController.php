<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use App\Modules\Relationship\Support\RelationshipDirection;
use App\Modules\Ticket\Actions\EscalateTicketRelationshipViaApi;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Resources\Api\V1\TicketRelationshipEscalationApiResource;
use App\Modules\Ticket\Resources\Api\V1\TicketRelationshipLinkApiResource;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** Ticket owns these operational adapters; Relationship retains destination policy, custody and actual outbound sync. */
class TicketRelationshipApiController extends Controller
{
    #[OA\Get(path: '/api/v1/tickets/{ticket}/relationship-targets', operationId: 'ticketTicketRelationshipApiControllertargets', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.relationships.read'], 'domain-permissions' => 'relationships.escalate', 'additional-domain-permissions' => ['ticket.view']], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketRelationshipTargetReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function targets(Request $request, Ticket $ticket)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $rows = NexumRelationship::query()->active()->ticketCapable()->where(fn ($q) => $q->where('direction', '!=', RelationshipDirection::WE_ARE_PROVIDER)->orWhere('client_id', $ticket->client_id))
            ->orderBy('name')->orderBy('id')->paginate(DomainApiRead::pageSize($request), ['id', 'name', 'direction', 'client_id', 'vendor_id', 'remote_organization_name', 'status', 'health_status'])->withQueryString();
        $rows->through(fn ($row) => $row->only(['id', 'name', 'direction', 'client_id', 'vendor_id', 'remote_organization_name', 'status', 'health_status']));

        return JsonResource::collection($rows);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/relationships/escalate', operationId: 'ticketTicketRelationshipApiControllerescalate', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.relationships.escalate'], 'domain-permissions' => 'relationships.escalate', 'additional-domain-permissions' => ['ticket.view']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TicketRelationshipEscalationRequest')), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRelationshipEscalationReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 202, description: 'Outbound outcome is pending or unresolved; follow the receipt read-back URL and do not resend blindly.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRelationshipEscalationReadback')])), new OA\Response(response: 409, description: 'Request key conflict or unresolved preceding attempt', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function escalate(Request $request, Ticket $ticket, EscalateTicketRelationshipViaApi $escalate)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['relationship_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|max:191']);
        $receipt = $escalate->handle($ticket, $request->user(), $data);

        return (new TicketRelationshipEscalationApiResource($receipt))->response()->setStatusCode(in_array(data_get($receipt->metadata, 'operation_status'), ['reserved', 'unresolved'], true) ? 202 : 200);
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/relationship-escalations', operationId: 'ticketTicketRelationshipApiControllerindex', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.relationships.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketRelationshipEscalationReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function index(Request $request, Ticket $ticket)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);

        return TicketRelationshipEscalationApiResource::collection(TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'api_relationship_escalation')->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/relationship-escalations/{escalation}', operationId: 'ticketTicketRelationshipApiControllershow', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'escalation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.relationships.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRelationshipEscalationReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(Request $request, Ticket $ticket, int $escalation)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);

        return new TicketRelationshipEscalationApiResource(TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'api_relationship_escalation')->findOrFail($escalation));
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/relationship-links', operationId: 'ticketTicketRelationshipApiControllerlinks', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.relationships.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketRelationshipLinkReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function links(Request $request, Ticket $ticket)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);

        return TicketRelationshipLinkApiResource::collection(NexumSyncLink::query()->where('domain', 'ticket')->where('local_type', $ticket->getMorphClass())->where('local_id', $ticket->id)
            ->orderBy('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }
}
