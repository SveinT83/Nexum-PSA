<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Ticket\Actions\AcknowledgeTicketMessage;
use App\Modules\Ticket\Actions\ApplyTicketWorkflowActionTrigger;
use App\Modules\Ticket\Actions\MarkTicketAsNotTicket;
use App\Modules\Ticket\Actions\MarkTicketRead;
use App\Modules\Ticket\Actions\SoftDeleteTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Resources\Api\V1\TicketLifecycleResource;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketAssignmentEngine;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketMergeSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TicketLifecycleController extends Controller
{
    #[OA\Get(path: '/api/v1/tickets/{ticket}/lifecycle-state', operationId: 'ticketTicketLifecycleControllerstate', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketLifecycleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function state(Ticket $ticket)
    {
        // A narrow deletion/merge receipt intentionally supports already-soft-deleted records.
        return new TicketLifecycleResource($ticket);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/read', operationId: 'ticketTicketLifecycleControllerread', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.read-state.write'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketLifecycleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function read(Request $request, Ticket $ticket, MarkTicketRead $read)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        DB::transaction(function () use ($request, $ticket, $read) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $read->handle($ticket, $request->user());
        });

        return new TicketLifecycleResource($ticket->refresh());
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/messages/{message}/read', operationId: 'ticketTicketLifecycleControllerreadMessage', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.read-state.write'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketLifecycleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function readMessage(Request $request, Ticket $ticket, TicketMessage $message, AcknowledgeTicketMessage $read)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        abort_unless((int) $message->ticket_id === (int) $ticket->id, 404);
        DB::transaction(function () use ($request, $ticket, $message, $read) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $message = TicketMessage::query()->where('ticket_id', $ticket->id)->lockForUpdate()->findOrFail($message->id);
            $read->handle($ticket, $message, $request->user());
        });

        return new TicketLifecycleResource($ticket->refresh());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/assignment-preview', operationId: 'ticketTicketLifecycleControllerassignmentPreview', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.assign'], 'domain-permissions' => 'ticket.assign', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketAssignmentPreview')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function assignmentPreview(Request $request, Ticket $ticket, TicketAssignmentEngine $engine)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $reason = app(TicketActionGuard::class)->reason($ticket, TicketAction::ASSIGN_OWNER, $request->user());

        return response()->json(['data' => ['ticket_id' => $ticket->id, 'current_owner_id' => $ticket->owner_id,
            'planned_owner_id' => $reason ? null : $engine->plan($ticket, force: true), 'allowed' => $reason === null, 'reason' => $reason]]);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/assignment', operationId: 'ticketTicketLifecycleControllerassign', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.assign'], 'domain-permissions' => 'ticket.assign', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['idempotency_key'], properties: [new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 100)])), responses: [new OA\Response(response: 409, description: 'Conflicting retry key or stale draft checksum', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketLifecycleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function assign(Request $request, Ticket $ticket, TicketAssignmentEngine $engine)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['idempotency_key' => ['required', 'string', 'max:100']]);
        $ticket = DB::transaction(function () use ($request, $ticket, $engine, $data) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $existing = $ticket->events()->where('type', 'api_assignment_confirmed')->where('metadata->idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ((int) $existing->actor_id !== (int) $request->user()->id) {
                    throw new ConflictHttpException('The assignment key belongs to a different actor.');
                }

                return $ticket;
            }
            if ($reason = app(TicketActionGuard::class)->reason($ticket, TicketAction::ASSIGN_OWNER, $request->user())) {
                throw ValidationException::withMessages(['assignment' => $reason]);
            }
            $before = $ticket->owner_id;
            $owner = $engine->assign($ticket, force: true);
            if (! $owner) {
                throw ValidationException::withMessages(['assignment' => 'No matching assignment rule or available technician was found.']);
            }
            if ((int) $before !== (int) $owner) {
                app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::ASSIGN_OWNER, $request->user());
            }
            TicketEvent::create(['ticket_id' => $ticket->id, 'actor_id' => $request->user()->id, 'type' => 'api_assignment_confirmed',
                'message' => 'Ticket API assignment confirmed.', 'after' => ['owner_id' => $owner], 'metadata' => ['idempotency_key' => $data['idempotency_key']]]);

            return $ticket->refresh();
        });

        return new TicketLifecycleResource($ticket);
    }

    #[OA\Delete(path: '/api/v1/tickets/{ticket}', operationId: 'ticketTicketLifecycleControllerdelete', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.delete'], 'domain-permissions' => 'ticket.delete', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['expected_snapshot'], properties: [new OA\Property(property: 'expected_snapshot', type: 'string', minLength: 64, maxLength: 64)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketLifecycleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function delete(Request $request, Ticket $ticket, SoftDeleteTicket $delete)
    {
        return $this->classifyOrDelete($request, $ticket, function (Ticket $current) use ($request, $delete): void {
            $delete->handle($current, $request->user());
        }, false);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/not-ticket', operationId: 'ticketTicketLifecycleControllernotTicket', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.classify'], 'domain-permissions' => 'ticket.delete', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['expected_snapshot'], properties: [new OA\Property(property: 'expected_snapshot', type: 'string', minLength: 64, maxLength: 64)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketLifecycleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function notTicket(Request $request, Ticket $ticket, MarkTicketAsNotTicket $classify)
    {
        return $this->classifyOrDelete($request, $ticket, function (Ticket $current) use ($request, $classify): void {
            $classify->handle($current, $request->user());
        }, true);
    }

    private function classifyOrDelete(Request $request, Ticket $ticket, \Closure $apply, bool $classification)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['expected_snapshot' => ['required', 'string', 'size:64']]);
        try {
            $ticket = DB::transaction(function () use ($ticket, $data, $apply, $classification) {
                $ticket = Ticket::withTrashed()->lockForUpdate()->findOrFail($ticket->id);
                if ($ticket->trashed()) {
                    if ($classification && empty($ticket->metadata['not_ticket'])) {
                        throw ValidationException::withMessages(['ticket' => 'This Ticket was deleted without not-ticket classification.']);
                    }

                    return $ticket;
                }
                app(TicketMutationScopeGuard::class)->assert($ticket);
                if (! hash_equals(TicketMergeSnapshot::fingerprint($ticket), $data['expected_snapshot'])) {
                    throw ValidationException::withMessages(['expected_snapshot' => 'Ticket changed. Read and review its current lifecycle state before retrying.']);
                }
                $apply($ticket);

                return $ticket->refresh();
            });
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['ticket' => $exception->getMessage()]);
        }

        return new TicketLifecycleResource($ticket);
    }
}
