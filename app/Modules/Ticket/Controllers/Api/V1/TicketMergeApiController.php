<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Ticket\Actions\DismissTicketMergeSuggestion;
use App\Modules\Ticket\Actions\MergeTicketsViaApi;
use App\Modules\Ticket\Services\TicketMergeSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** Merging across any Client is permitted without a preview, as explicitly approved by Svein. */
class TicketMergeApiController extends Controller
{
    #[OA\Get(path: '/api/v1/tickets/merge-suggestions', operationId: 'ticketTicketMergeApiControllersuggestions', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketMergeSuggestionReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function suggestions(Request $request, TicketMergeSuggestionService $suggestions)
    {
        $rows = $suggestions->suggestionsForIndex(100)->map(fn ($row) => [
            'target_ticket_id' => $row['target']->id, 'source_ticket_ids' => $row['sources']->pluck('id')->map('intval')->values()->all(),
            'confidence' => (int) $row['confidence'], 'reason' => $row['reason'],
            'tickets' => $row['tickets']->map(fn ($ticket) => $ticket->only(['id', 'ticket_key', 'subject', 'client_id']))->values()->all(),
        ]);

        return JsonResource::collection(DomainApiRead::paginateCollection($rows, $request));
    }

    #[OA\Post(path: '/api/v1/tickets/merge', operationId: 'ticketTicketMergeApiControllermerge', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.merge'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => [], 'conditional-authorization' => ['Every current linked mailbox: native MailOrganize permission. No preview required; cross-client sources are allowed.']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TicketMergeRequest')), responses: [new OA\Response(response: 409, description: 'Conflicting retry key or stale draft checksum', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketMergeReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function merge(Request $request, MergeTicketsViaApi $merge)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['target_ticket_id' => 'required|integer|min:1', 'source_ticket_ids' => 'required|array|min:1|max:100', 'source_ticket_ids.*' => 'required|integer|min:1|distinct|different:target_ticket_id',
            'reason' => 'nullable|string|max:2000', 'idempotency_key' => 'required|string|max:191']);
        abort_if(in_array((int) $data['target_ticket_id'], array_map('intval', $data['source_ticket_ids']), true), 422, 'A Ticket cannot merge into itself.');
        $target = $merge->handle($request->user(), $data);

        return response()->json(['data' => ['target_ticket_id' => $target->id, 'target_ticket_key' => $target->ticket_key,
            'source_ticket_ids' => array_map('intval', $data['source_ticket_ids']), 'merged_count' => count($data['source_ticket_ids']), 'readback_url' => route('api.v1.tickets.lifecycle-state.show', $target)]]);
    }

    #[OA\Post(path: '/api/v1/tickets/merge-suggestions/dismiss', operationId: 'ticketTicketMergeApiControllerdismiss', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.merge'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TicketMergeDismissRequest')), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketMergeDismissalReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function dismiss(Request $request, DismissTicketMergeSuggestion $dismiss)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['ticket_ids' => 'required|array|min:2|max:100', 'ticket_ids.*' => 'required|integer|min:1|distinct', 'reason' => 'nullable|string|max:255']);
        $dismiss->handle($data['ticket_ids'], $request->user(), $data['reason'] ?? null);

        return response()->json(['data' => ['ticket_ids' => array_map('intval', $data['ticket_ids']), 'dismissed' => true]]);
    }
}
