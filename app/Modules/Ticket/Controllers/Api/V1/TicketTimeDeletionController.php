<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Ticket\Actions\DeleteTicketTimeEntry;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Resources\Api\V1\TicketTimeEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Removal uses a Ticket action, never a generic model or database API. */
final class TicketTimeDeletionController extends Controller
{
    #[OA\Get(path: '/api/v1/tickets/{ticket}/time-entries/{timeEntry}/delete-preview', operationId: 'ticketTimeDeletionPreview', security: [['bearerAuth' => []]], tags: ['Tickets'],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'timeEntry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        x: ['required-scopes' => ['tickets.time.delete'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => ['ticket.register_time', 'ticket.update']],
        responses: [new OA\Response(response: 200, description: 'Exact record and current deletion eligibility', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketTimeDeletionPreview')])),
            new OA\Response(response: 401, description: 'Authentication, current authority, nested ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 403, description: 'Authentication, current authority, nested ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 404, description: 'Authentication, current authority, nested ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 409, description: 'Stale preview, protected state or conflicting retry', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 422, description: 'Authentication, current authority, nested ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 503, description: 'Required existing schema is unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function preview(Request $request, Ticket $ticket, TicketTimeEntry $timeEntry, DeleteTicketTimeEntry $delete): JsonResponse
    {
        $data = $delete->preview($ticket, $timeEntry, $request->user());
        $data['entry'] = (new TicketTimeEntryResource($timeEntry))->resolve($request);

        return response()->json(['data' => $data]);
    }

    #[OA\Delete(path: '/api/v1/tickets/{ticket}/time-entries/{timeEntry}', operationId: 'ticketTimeDeletionDelete', security: [['bearerAuth' => []]], tags: ['Tickets'],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'timeEntry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        x: ['required-scopes' => ['tickets.time.delete'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => ['ticket.register_time', 'ticket.update']],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TicketTimeDeletionRequest')),
        responses: [new OA\Response(response: 200, description: 'Persisted deletion audit receipt', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketTimeDeletionReceipt')])),
            new OA\Response(response: 401, description: 'Authentication, current authority, nested ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 403, description: 'Authentication, current authority, nested ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 404, description: 'Authentication, current authority, nested ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 409, description: 'Stale preview, protected state or conflicting retry', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 422, description: 'Authentication, current authority, nested ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 503, description: 'Required existing schema is unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function destroy(Request $request, Ticket $ticket, int $timeEntry, DeleteTicketTimeEntry $delete): JsonResponse
    {
        $delete->authorize($ticket, $request->user());
        $input = DomainApiInput::validate($request, [
            'reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/u'],
            'preview_token' => ['required', 'string', 'max:8192'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_.:-]{8,100}$/D'],
        ]);
        $event = $delete->handle($ticket, $timeEntry, $request->user(), $input);

        return response()->json(['data' => $delete->receipt($ticket, $event, $request->user())]);
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/time-entry-deletions/{event}', operationId: 'ticketTimeDeletionReceipt', security: [['bearerAuth' => []]], tags: ['Tickets'],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        x: ['required-scopes' => ['tickets.time.delete'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => ['ticket.register_time', 'ticket.update']],
        responses: [new OA\Response(response: 200, description: 'Persisted deletion audit receipt', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketTimeDeletionReceipt')])),
            new OA\Response(response: 401, description: 'Authentication, current authority, nested ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 403, description: 'Authentication, current authority, nested ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 404, description: 'Authentication, current authority, nested ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 409, description: 'Stale preview, protected state or conflicting retry', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 422, description: 'Authentication, current authority, nested ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 503, description: 'Required existing schema is unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(Request $request, Ticket $ticket, TicketEvent $event, DeleteTicketTimeEntry $delete): JsonResponse
    {
        return response()->json(['data' => $delete->receipt($ticket, $event, $request->user())]);
    }
}
