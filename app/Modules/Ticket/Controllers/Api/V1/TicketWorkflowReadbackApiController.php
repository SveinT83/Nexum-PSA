<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketWorkflowEvidence;
use App\Modules\Ticket\Models\TicketWorkflowReview;
use App\Modules\Ticket\Resources\Api\V1\TicketQuoteVersionRecordResource;
use App\Modules\Ticket\Resources\Api\V1\TicketWorkflowEvidenceRecordResource;
use App\Modules\Ticket\Resources\Api\V1\TicketWorkflowReviewRecordResource;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Ticket-owned workflow and commercial results can be read back without invoking browser GET side effects. */
final class TicketWorkflowReadbackApiController extends Controller
{
    #[OA\Get(path: '/api/v1/tickets/{ticket}/workflow-reviews', operationId: 'ticketTicketWorkflowReadbackApiControllerreviews', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.workflow.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketWorkflowReviewRecord')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function reviews(Request $request, Ticket $ticket)
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);

        return TicketWorkflowReviewRecordResource::collection($ticket->workflowReviews()->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/workflow-reviews/{review}', operationId: 'ticketTicketWorkflowReadbackApiControllerreview', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'review', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.workflow.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowReviewRecord')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function review(Ticket $ticket, TicketWorkflowReview $review)
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);
        abort_unless((int) $review->ticket_id === (int) $ticket->id, 404);

        return new TicketWorkflowReviewRecordResource($review);
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/workflow-evidence', operationId: 'ticketTicketWorkflowReadbackApiControllerevidence', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.workflow.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketWorkflowEvidenceRecord')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function evidence(Request $request, Ticket $ticket)
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);

        return TicketWorkflowEvidenceRecordResource::collection($ticket->workflowEvidence()->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/workflow-evidence/{evidence}', operationId: 'ticketTicketWorkflowReadbackApiControllerevidenceRecord', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'evidence', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.workflow.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowEvidenceRecord')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function evidenceRecord(Ticket $ticket, TicketWorkflowEvidence $evidence)
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);
        abort_unless((int) $evidence->ticket_id === (int) $ticket->id, 404);

        return new TicketWorkflowEvidenceRecordResource($evidence);
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/quote-versions', operationId: 'ticketTicketWorkflowReadbackApiControllerquotes', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.quotes.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketQuoteVersionRecord')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function quotes(Request $request, Ticket $ticket)
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);

        return TicketQuoteVersionRecordResource::collection($this->quoteQuery($ticket)->with('lines')->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/quote-versions/{version}', operationId: 'ticketTicketWorkflowReadbackApiControllerquote', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.quotes.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketQuoteVersionRecord')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function quote(Ticket $ticket, int $version)
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);

        return new TicketQuoteVersionRecordResource($this->quoteQuery($ticket)->with('lines')->findOrFail($version));
    }

    private function quoteQuery(Ticket $ticket)
    {
        $opportunityId = $ticket->salesContext?->opportunity_id;

        return SalesQuoteVersion::query()->whereHas('quote', fn ($q) => $q->where('opportunity_id', $opportunityId)->whereNotNull('opportunity_id'));
    }
}
