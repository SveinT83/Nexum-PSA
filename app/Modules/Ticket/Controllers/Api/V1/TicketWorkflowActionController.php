<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Ticket\Actions\AcceptTicketQuoteFromMessage;
use App\Modules\Ticket\Actions\ClassifyTicketWorkflowEvidence;
use App\Modules\Ticket\Actions\CloseTicket;
use App\Modules\Ticket\Actions\ConvertApprovedTicketPlannedLine;
use App\Modules\Ticket\Actions\DecideTicketWorkflowReview;
use App\Modules\Ticket\Actions\DeleteTicketPlannedLine;
use App\Modules\Ticket\Actions\EnsureTicketSalesQuote;
use App\Modules\Ticket\Actions\EscalateTicketWorkflow;
use App\Modules\Ticket\Actions\ProcessAcceptedTicketQuote;
use App\Modules\Ticket\Actions\RecordTicketTimerStarted;
use App\Modules\Ticket\Actions\RegisterTicketTimeEntry;
use App\Modules\Ticket\Actions\RequestTicketPurchase;
use App\Modules\Ticket\Actions\RequestTicketWorkflowReview;
use App\Modules\Ticket\Actions\SendTicketSalesQuote;
use App\Modules\Ticket\Actions\StoreTicketCostOrQuoteScope;
use App\Modules\Ticket\Actions\StoreTicketPlannedLine;
use App\Modules\Ticket\Actions\TransitionTicketWorkflow;
use App\Modules\Ticket\Actions\VoidAcceptedTicketSalesQuote;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketPlannedLine;
use App\Modules\Ticket\Models\TicketWorkflowReview;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use App\Modules\Ticket\Resources\Api\V1\TicketResource;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class TicketWorkflowActionController extends Controller
{
    #[OA\Post(path: '/api/v1/tickets/{ticket}/timer/start', operationId: 'ticketTicketWorkflowActionControllerstartTimerPost', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.register_time', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketTimerResult')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function startTimer(Request $request, Ticket $ticket, RecordTicketTimerStarted $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $result = $action->handle($ticket, $request->user());

        return response()->json(['data' => [
            'ticket' => (new TicketResource($this->load($result['ticket'])))->resolve(),
            'transitioned' => $result['transitioned'],
        ]]);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/time-entries', operationId: 'ticketTicketWorkflowActionControllerstoreTimeEntryPost', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.register_time', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'work_date', type: 'string', format: 'date'), new OA\Property(property: 'minutes', type: 'integer', minimum: 1, maximum: 1440), new OA\Property(property: 'rate_key', type: 'string', maxLength: 100), new OA\Property(property: 'invoice_text', type: 'string', maxLength: 2000), new OA\Property(property: 'note', type: 'string', nullable: true, maxLength: 2000)], required: ['work_date', 'minutes', 'rate_key', 'invoice_text'])), responses: [new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketTimeEntryRecord'), new OA\Property(property: 'ticket', ref: '#/components/schemas/TicketApiRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function storeTimeEntry(
        Request $request,
        Ticket $ticket,
        TicketTimeRateOptions $rates,
        RegisterTicketTimeEntry $action,
    ) {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'work_date' => ['required', 'date'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'rate_key' => ['required', 'string', 'max:100'],
            'invoice_text' => ['required', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $rate = $rates->findForTicket($ticket, $data['rate_key']);

        if (! $rate) {
            throw ValidationException::withMessages([
                'rate_key' => 'Select an available time rate for this Ticket.',
            ]);
        }

        $entry = $action->handle($ticket, $data, $rate, $request->user());

        return response()->json([
            'data' => $entry->toArray(),
            'ticket' => (new TicketResource($this->load($ticket)))->resolve(),
        ], 201);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/cost-entries', operationId: 'ticketTicketWorkflowActionControllerstoreCostEntryPost', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'cost_mode', type: 'string', enum: ['storage', 'manual']), new OA\Property(property: 'storage_item_id', type: 'integer', nullable: true, description: 'required_if:cost_mode,storage; exists:storage_items,id. Context and current policy are validated before writes.'), new OA\Property(property: 'item_name', type: 'string', nullable: true, maxLength: 255, description: 'required_if:cost_mode,manual. Context and current policy are validated before writes.'), new OA\Property(property: 'quantity', type: 'integer', minimum: 1, maximum: 100000), new OA\Property(property: 'unit_price_ex_vat', type: 'number', nullable: true, minimum: 0, maximum: 9999999999.99, description: 'required_if:cost_mode,manual. Context and current policy are validated before writes.'), new OA\Property(property: 'currency', type: 'string', nullable: true), new OA\Property(property: 'invoice_text', type: 'string', nullable: true, maxLength: 2000), new OA\Property(property: 'note', type: 'string', nullable: true, maxLength: 2000)], required: ['cost_mode', 'quantity'])), responses: [new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', anyOf: [new OA\Schema(ref: '#/components/schemas/TicketCostEntryRecord'), new OA\Schema(ref: '#/components/schemas/TicketPlannedLineRecord')]), new OA\Property(property: 'ticket', ref: '#/components/schemas/TicketApiRecord'), new OA\Property(property: 'data_type', type: 'string'), new OA\Property(property: 'routed_to', type: 'string'), new OA\Property(property: 'reason', type: 'string', nullable: true)], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function storeCostEntry(
        Request $request,
        Ticket $ticket,
        StoreTicketCostOrQuoteScope $storeCostOrQuoteScope,
    ) {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'cost_mode' => ['required', Rule::in(['storage', 'manual'])],
            'storage_item_id' => ['nullable', 'required_if:cost_mode,storage', 'integer', 'exists:storage_items,id'],
            'item_name' => ['nullable', 'required_if:cost_mode,manual', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit_price_ex_vat' => ['nullable', 'required_if:cost_mode,manual', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'invoice_text' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $storeCostOrQuoteScope->handle($ticket, $data, $request->user());

        return response()->json([
            'data_type' => $result['record_type'],
            'routed_to' => $result['route'],
            'reason' => $result['reason'],
            'data' => $result['record']->toArray(),
            'ticket' => (new TicketResource($this->load($ticket)))->resolve(),
        ], 201);
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/workflow-decisions', operationId: 'ticketTicketWorkflowActionControllerdecisionsGet', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.workflow.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowDecisions')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function decisions(Request $request, Ticket $ticket, TicketWorkflowRuntime $runtime, TicketActionGuard $guard)
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);
        $transitions = $runtime->availableTransitionDecisions($ticket);

        return response()->json(['data' => [
            'workflow_id' => $ticket->workflow_id,
            'workflow_version_id' => $ticket->workflow_version_id,
            'state' => $runtime->currentState($ticket),
            'steps' => $runtime->stateProgress($ticket, $transitions),
            'transitions' => $transitions,
            'escalations' => $runtime->escalationDecisions($ticket),
            'actions' => $guard->decisionMap($ticket, $request->user()),
        ]]);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/workflow-transitions/{transitionKey}', operationId: 'ticketTicketWorkflowActionControllertransitionPost', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'transitionKey', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'idempotency_key', type: 'string', nullable: true, maxLength: 100)])), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketApiRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function transition(Request $request, Ticket $ticket, string $transitionKey, TransitionTicketWorkflow $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['idempotency_key' => ['nullable', 'string', 'max:100']]);

        return new TicketResource($this->load($action->handle(
            $ticket,
            $transitionKey,
            $request->user(),
            $data['idempotency_key'] ?? null,
            ruleEventSourceChannel: 'api',
            ruleEventSourceAction: 'TicketWorkflowActionController.transition',
        )));
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/workflow-escalations/{pathKey}', operationId: 'ticketTicketWorkflowActionControllerescalatePost', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'pathKey', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.workflow_escalate', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'owner_id', type: 'integer', nullable: true), new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 2000), new OA\Property(property: 'allow_repeat', type: 'boolean', nullable: true)])), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketApiRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function escalate(Request $request, Ticket $ticket, string $pathKey, EscalateTicketWorkflow $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'owner_id' => ['nullable', 'integer', Rule::exists((new User)->getTable(), 'id')],
            'reason' => ['nullable', 'string', 'max:2000'],
            'allow_repeat' => ['nullable', 'boolean'],
        ]);

        return new TicketResource($this->load($action->handle(
            $ticket,
            $pathKey,
            $data,
            $request->user(),
            ruleEventSourceChannel: 'api',
            ruleEventSourceAction: 'TicketWorkflowActionController.escalate',
        )));
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/close', operationId: 'ticketTicketWorkflowActionControllerclosePost', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.close', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'outcome', type: 'string', nullable: true, enum: ['completed', 'customer_declined', 'cancelled', 'no_sale']), new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 2000)])), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketApiRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function close(Request $request, Ticket $ticket, CloseTicket $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'outcome' => ['nullable', Rule::in(['completed', 'customer_declined', 'cancelled', 'no_sale'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return new TicketResource($this->load($action->handle($ticket, $request->user(), $data['outcome'] ?? 'completed', $data['reason'] ?? null)));
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/planned-lines', operationId: 'ticketTicketWorkflowActionControllerstorePlannedLinePost', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.plan_cost', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'storage_item_id', type: 'integer', nullable: true, description: 'exists:storage_items,id. Context and current policy are validated before writes.'), new OA\Property(property: 'line_type', type: 'string', nullable: true, maxLength: 50), new OA\Property(property: 'section', type: 'string', nullable: true, maxLength: 100), new OA\Property(property: 'downstream_type', type: 'string', nullable: true, maxLength: 100), new OA\Property(property: 'sku', type: 'string', nullable: true, maxLength: 100), new OA\Property(property: 'name', type: 'string', nullable: true, maxLength: 255, description: 'required_without:storage_item_id. Context and current policy are validated before writes.'), new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 2000), new OA\Property(property: 'quantity', type: 'number', minimum: 0.01, maximum: 100000), new OA\Property(property: 'unit', type: 'string', nullable: true, maxLength: 50), new OA\Property(property: 'unit_cost_ex_vat', type: 'number', nullable: true, minimum: 0), new OA\Property(property: 'unit_price_ex_vat', type: 'number', nullable: true, minimum: 0), new OA\Property(property: 'vat_rate', type: 'number', nullable: true, minimum: 0, maximum: 100)], required: ['quantity'])), responses: [new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketPlannedLineRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function storePlannedLine(Request $request, Ticket $ticket, StoreTicketPlannedLine $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $line = $action->handle($ticket, $this->plannedLineData($request), $request->user());

        return response()->json(['data' => $this->line($line)], 201);
    }

    #[OA\Delete(path: '/api/v1/tickets/{ticket}/planned-lines/{plannedLine}', operationId: 'ticketTicketWorkflowActionControllerdestroyPlannedLineDelete', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'plannedLine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.plan_cost', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 204, description: 'Deleted with no response body'), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function destroyPlannedLine(Request $request, Ticket $ticket, TicketPlannedLine $plannedLine, DeleteTicketPlannedLine $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $action->handle($ticket, $plannedLine, $request->user());

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/planned-lines/{plannedLine}/convert', operationId: 'ticketTicketWorkflowActionControllerconvertPlannedLinePost', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Manual cost conversion requires ticket.update; storage conversion also requires the native storage.reserve policy.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'plannedLine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.update|storage.reserve', 'additional-domain-permissions' => [], 'conditional-authorization' => ['Storage-backed line: native storage.reserve policy. Manual line: ticket.update.']], responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketCostEntryRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function convertPlannedLine(Request $request, Ticket $ticket, TicketPlannedLine $plannedLine, ConvertApprovedTicketPlannedLine $action, ProcessAcceptedTicketQuote $processor)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $entry = $action->handle($ticket, $plannedLine, $request->user());
        $this->syncAcceptedQuoteDelivery($ticket, $plannedLine, $processor, $request->user());

        return response()->json(['data' => $entry->toArray()]);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/planned-lines/{plannedLine}/purchase', operationId: 'ticketTicketWorkflowActionControllerrequestPurchasePost', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Creates/read-backs an internal supplier-order draft; it does not send a supplier order.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'plannedLine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'storage.purchase_manage', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketPurchaseLineRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function requestPurchase(Request $request, Ticket $ticket, TicketPlannedLine $plannedLine, RequestTicketPurchase $action, ProcessAcceptedTicketQuote $processor)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $line = $action->handle($ticket, $plannedLine, $request->user());
        $this->syncAcceptedQuoteDelivery($ticket, $plannedLine, $processor, $request->user());

        return response()->json(['data' => $line->load('purchaseOrder')->toArray()], 201);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/sales-quote', operationId: 'ticketTicketWorkflowActionControllercreateQuotePost', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Sales owns quote revisions, acceptance, delivery and order conversion.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'sales.quote_manage', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketQuoteVersionRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function createQuote(Request $request, Ticket $ticket, EnsureTicketSalesQuote $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);

        return response()->json(['data' => $this->quote($action->handle($ticket, $request->user()))], 201);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/sales-quote/send', operationId: 'ticketTicketWorkflowActionControllersendQuotePost', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Sales owns quote revisions, acceptance, delivery and order conversion.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'sales.email_send', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'subject', type: 'string', nullable: true, maxLength: 255), new OA\Property(property: 'body', type: 'string', nullable: true, maxLength: 10000), new OA\Property(property: 'reply_contact_id', type: 'integer', nullable: true, description: 'exists:client_users,id. Context and current policy are validated before writes.'), new OA\Property(property: 'cc', type: 'string', nullable: true, maxLength: 1000)])), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketQuoteVersionRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function sendQuote(Request $request, Ticket $ticket, SendTicketSalesQuote $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'reply_contact_id' => ['nullable', 'integer', 'exists:client_users,id'],
            'cc' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->quote($action->handle($ticket, $data, $request->user()))]);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/sales-quote/versions/{version}/void', operationId: 'ticketTicketWorkflowActionControllervoidAcceptedQuotePost', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Sales owns quote revisions, acceptance, delivery and order conversion.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'sales.quote_manage', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 2000)], required: ['reason'])), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketQuoteVersionRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function voidAcceptedQuote(Request $request, Ticket $ticket, SalesQuoteVersion $version, VoidAcceptedTicketSalesQuote $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $this->quote($action->handle($ticket, $version, $data, $request->user()))]);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/messages/{message}/quote-versions/{version}/accept', operationId: 'ticketTicketWorkflowActionControlleracceptQuoteFromMessagePost', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Sales owns quote revisions, acceptance, delivery and order conversion.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.approval_record', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'name', type: 'string', maxLength: 255), new OA\Property(property: 'comment', type: 'string', nullable: true, maxLength: 2000)], required: ['name'])), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketQuoteVersionRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function acceptQuoteFromMessage(Request $request, Ticket $ticket, TicketMessage $message, SalesQuoteVersion $version, AcceptTicketQuoteFromMessage $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'name' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $version->loadMissing('quote');

        return response()->json(['data' => $this->quote($action->handle($ticket, $message, $version, $data, $request->user()))]);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/workflow-reviews', operationId: 'ticketTicketWorkflowActionControllerrequestReviewPost', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.review_request', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'gate_key', type: 'string', nullable: true, maxLength: 100), new OA\Property(property: 'assigned_reviewer_id', type: 'integer', nullable: true), new OA\Property(property: 'comment', type: 'string', nullable: true, maxLength: 2000), new OA\Property(property: 'separation_of_duties', type: 'boolean', nullable: true)])), responses: [new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowReviewRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function requestReview(Request $request, Ticket $ticket, RequestTicketWorkflowReview $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'gate_key' => ['nullable', 'string', 'max:100'],
            'assigned_reviewer_id' => ['nullable', 'integer', Rule::exists((new User)->getTable(), 'id')],
            'comment' => ['nullable', 'string', 'max:2000'],
            'separation_of_duties' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => (new \App\Modules\Ticket\Resources\Api\V1\TicketWorkflowReviewRecordResource($action->handle($ticket, $data, $request->user())))->resolve($request)], 201);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/workflow-reviews/{review}/decision', operationId: 'ticketTicketWorkflowActionControllerdecideReviewPost', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'review', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.review_senior', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'decision', type: 'string', enum: ['approved', 'sent_back']), new OA\Property(property: 'comment', type: 'string', nullable: true, maxLength: 2000)], required: ['decision'])), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowReviewRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function decideReview(Request $request, Ticket $ticket, TicketWorkflowReview $review, DecideTicketWorkflowReview $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        abort_unless((int) $review->ticket_id === (int) $ticket->id, 404);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'decision' => ['required', Rule::in(['approved', 'sent_back'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => (new \App\Modules\Ticket\Resources\Api\V1\TicketWorkflowReviewRecordResource($action->handle($review, $data['decision'], $data['comment'] ?? null, $request->user())))->resolve($request)]);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/workflow-evidence', operationId: 'ticketTicketWorkflowActionControllerclassifyEvidencePost', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.actions'], 'domain-permissions' => 'ticket.evidence_classify', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'evidence_type', type: 'string', enum: ['customer_response', 'signature', 'manual_approval']), new OA\Property(property: 'source_type', type: 'string', enum: ['message', 'attachment']), new OA\Property(property: 'source_id', type: 'integer'), new OA\Property(property: 'scope_key', type: 'string', nullable: true, maxLength: 255), new OA\Property(property: 'subject_name', type: 'string', nullable: true, maxLength: 255), new OA\Property(property: 'evidenced_at', type: 'string', nullable: true, format: 'date-time'), new OA\Property(property: 'comment', type: 'string', nullable: true, maxLength: 2000)], required: ['evidence_type', 'source_type', 'source_id'])), responses: [new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowEvidenceRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function classifyEvidence(Request $request, Ticket $ticket, ClassifyTicketWorkflowEvidence $action)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'evidence_type' => ['required', Rule::in(['customer_response', 'signature', 'manual_approval'])],
            'source_type' => ['required', Rule::in(['message', 'attachment'])],
            'source_id' => ['required', 'integer'],
            'scope_key' => ['nullable', 'string', 'max:255'],
            'subject_name' => ['nullable', 'string', 'max:255'],
            'evidenced_at' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => (new \App\Modules\Ticket\Resources\Api\V1\TicketWorkflowEvidenceRecordResource($action->handle($ticket, $data, $request->user())))->resolve($request)], 201);
    }

    private function plannedLineData(Request $request): array
    {
        return \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'storage_item_id' => ['nullable', 'integer', 'exists:storage_items,id'],
            'line_type' => ['nullable', 'string', 'max:50'],
            'section' => ['nullable', 'string', 'max:100'],
            'downstream_type' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:100'],
            'name' => ['required_without:storage_item_id', 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'unit' => ['nullable', 'string', 'max:50'],
            'unit_cost_ex_vat' => ['nullable', 'numeric', 'min:0'],
            'unit_price_ex_vat' => ['nullable', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
    }

    private function syncAcceptedQuoteDelivery(Ticket $ticket, TicketPlannedLine $plannedLine, ProcessAcceptedTicketQuote $processor, ?User $actor): void
    {
        $version = $plannedLine->refresh()->approvedQuoteVersion()->first();
        if ($version?->status === 'accepted') {
            $processor->handle($ticket->refresh(), $version, $actor);
        }
    }

    private function load(Ticket $ticket): Ticket
    {
        return $ticket->refresh()->load(['queue', 'status', 'priority', 'client', 'site', 'contact', 'owner', 'asset', 'workflow', 'workflowVersion']);
    }

    private function line(TicketPlannedLine $line): array
    {
        return $line->load(['storageItem', 'approvedQuoteVersion', 'convertedCostEntry'])->toArray();
    }

    private function quote(SalesQuoteVersion $version): array
    {
        return (new \App\Modules\Ticket\Resources\Api\V1\TicketQuoteVersionRecordResource($version->load('lines')))->resolve(request());
    }
}
