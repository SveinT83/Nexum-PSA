<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\DocumentationRequest;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Ticket\Actions\RequestTicketDocumentation;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Queries\TicketSlaReportQuery;
use App\Modules\Ticket\Resources\Api\V1\TicketDocumentationRequestResource;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Explicit follow-up/read-back and the same stored-timestamp SLA calculations exposed in the browser. */
class TicketFollowUpApiController extends Controller
{
    #[OA\Get(path: '/api/v1/tickets/{ticket}/documentation-requests', operationId: 'ticketTicketFollowUpApiControllerindex', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.documentation.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketDocumentationRequestReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    #[OA\Get(path: '/api/v1/ticket-settings/documentation-requests', operationId: 'ticketTicketFollowUpApiControlleradministration', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['open', 'draft_ready', 'in_review', 'approved', 'publishing', 'publication_failed', 'completed']))], x: ['required-scopes' => ['tickets.settings.read'], 'domain-permissions' => 'ticket.manage_settings', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketDocumentationRequestReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function administration(Request $request)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['status' => 'sometimes|in:open,draft_ready,in_review,approved,publishing,publication_failed,completed']);

        return TicketDocumentationRequestResource::collection(DocumentationRequest::query()->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    public function index(Request $request, Ticket $ticket)
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);

        return TicketDocumentationRequestResource::collection(DocumentationRequest::query()->where('ticket_id', $ticket->id)->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/documentation-requests/{documentationRequest}', operationId: 'ticketTicketFollowUpApiControllershow', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'documentationRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.documentation.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketDocumentationRequestReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(Request $request, Ticket $ticket, int $documentationRequest)
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);

        return new TicketDocumentationRequestResource(DocumentationRequest::query()->where('ticket_id', $ticket->id)->findOrFail($documentationRequest));
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/documentation-requests', operationId: 'ticketTicketFollowUpApiControllerstore', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.documentation.request'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TicketDocumentationRequestPayload')), responses: [new OA\Response(response: 409, description: 'Conflicting retry key or stale draft checksum', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketDocumentationRequestReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 201, description: 'Record created', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketDocumentationRequestReadback')]))])]
    public function store(Request $request, Ticket $ticket, RequestTicketDocumentation $create)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['reason' => 'nullable|string|max:1000', 'idempotency_key' => 'required|string|max:191']);

        return new TicketDocumentationRequestResource($create->handle($ticket, $request->user(), $data['reason'] ?? null, $data['idempotency_key']));
    }

    #[OA\Get(path: '/api/v1/tickets/reports/sla', operationId: 'ticketTicketFollowUpApiControllersla', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'period', in: 'query', schema: new OA\Schema(type: 'string', enum: ['7', '30', '90', 'all'], default: '30')), new OA\Parameter(name: 'context', in: 'query', schema: new OA\Schema(type: 'string', enum: ['client', 'internal', 'all'], default: 'client')), new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15))], x: ['required-scopes' => ['tickets.reports.read'], 'domain-permissions' => 'report.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketSlaReportReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function sla(Request $request, TicketSlaReportQuery $report)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['period' => 'sometimes|in:7,30,90,all', 'context' => 'sometimes|in:client,internal,all', 'limit' => 'sometimes|integer|min:1|max:100']);
        $period = $data['period'] ?? '30';
        $context = $data['context'] ?? 'client';
        $from = match ($period) {
            '7' => now()->subDays(7), '90' => now()->subDays(90), 'all' => null, default => now()->subDays(30)
        };
        $tickets = $report->overdueTickets((int) ($data['limit'] ?? 15), $context)->map(fn ($ticket) => ['id' => $ticket->id, 'ticket_key' => $ticket->ticket_key, 'subject' => $ticket->subject,
            'client_id' => $ticket->client_id, 'client_name' => $ticket->client?->name, 'owner_name' => $ticket->owner?->name, 'status' => $ticket->status?->name, 'priority' => $ticket->priority?->name,
            'first_response_due_at' => $ticket->first_response_due_at?->toISOString(), 'resolve_due_at' => $ticket->resolve_due_at?->toISOString()])->values();

        return response()->json(['data' => ['period' => (string) $period, 'context' => $context, 'summary' => $report->summary($from, context: $context), 'overdue_tickets' => $tickets]]);
    }
}
