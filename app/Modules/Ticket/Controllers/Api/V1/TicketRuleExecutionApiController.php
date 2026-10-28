<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Ticket\Actions\ExecuteIdempotentTicketRuleApiCommand;
use App\Modules\Ticket\Actions\RetryTicketRuleAction;
use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Queries\TicketRuleExecutionDetailQuery;
use App\Modules\Ticket\Queries\TicketRuleExecutionIndexQuery;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Services\TicketRuleActionRetrySelector;
use App\Modules\Ticket\Services\TicketRuleApiFailure;
use App\Modules\Ticket\Services\TicketRuleEvidenceAccess;
use App\Modules\Ticket\Services\TicketRuleExecutionPresenter;
use App\Modules\Ticket\Services\TicketRuleFullRerunBoundary;
use App\Modules\Ticket\Services\TicketRuleRuntimeGate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** Internal execution evidence uses the same field-safe presenters and retry/rerun authority as the browser. */
class TicketRuleExecutionApiController extends Controller
{
    #[OA\Get(path: '/api/v1/ticket-rule-executions', operationId: 'ticketTicketRuleExecutionApiControllerindex', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.rules.executions.read'], 'domain-permissions' => 'ticket.rule_execution_view', 'additional-domain-permissions' => ['ticket.view']], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RuleRunSummary')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function index(Request $request, TicketRuleExecutionIndexQuery $query, TicketRuleExecutionPresenter $presenter, TicketRuleEvidenceAccess $access)
    {
        $filters = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['rule_id' => 'nullable|integer|min:1', 'ticket' => 'nullable|string|max:64', 'event' => 'nullable|string|max:80',
            'result' => 'nullable|in:running,succeeded,failed,no_change,loop_blocked', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'sort' => 'nullable|in:started_at,status,event,duration', 'direction' => 'nullable|in:asc,desc']);
        if (! $access->canUseOutcomeIndexControls($request->user())) {
            unset($filters['result']);
            if (in_array($filters['sort'] ?? null, ['status', 'duration'], true)) {
                unset($filters['sort'], $filters['direction']);
            }
        }
        $runs = $query->paginate($filters, DomainApiRead::pageSize($request));
        $runs->through(function ($run) use ($request, $presenter) {
            if ($run->ticket) {
                app(TicketMutationScopeGuard::class)->assert($run->ticket);
            }

            return $presenter->runSummary($run, $request->user());
        });

        return JsonResource::collection($runs);
    }

    #[OA\Get(path: '/api/v1/ticket-rule-executions/{run}', operationId: 'ticketTicketRuleExecutionApiControllershow', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.rules.executions.read'], 'domain-permissions' => 'ticket.rule_execution_view', 'additional-domain-permissions' => ['ticket.view']], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RuleRunDetail')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(Request $request, TicketRuleRun $run, TicketRuleExecutionPresenter $presenter, TicketRuleExecutionDetailQuery $detail, TicketRuleActionRetrySelector $selector, TicketRuleRuntimeGate $gate, TicketRuleFullRerunBoundary $rerun)
    {
        $run->load(['ticket', 'retryOf:id,status,started_at', 'events' => fn ($q) => $q->orderBy('sequence')->orderBy('id'),
            'executions' => fn ($q) => $q->with(['rule' => fn ($q) => $q->withTrashed(), 'version'])->orderBy('event_id')->orderBy('order_position')->orderBy('id'),
            'afterCommitResults' => fn ($q) => $q->orderBy('id')])->loadCount(['events', 'executions', 'actionResults']);
        if ($run->ticket) {
            app(TicketMutationScopeGuard::class)->assert($run->ticket);
        }
        $detail->hydrateBoundedActionAttempts($run);
        $evidence = $presenter->runDetail($run, $request->user());
        $canRetry = ! $evidence['restricted_evidence'] && $gate->enabled() && $run->ticket && $request->user()->can('ticket.rule_retry') && $request->user()->tokenCan('tickets.rules.retry');
        $evidence['retryable_action_ids'] = $canRetry ? $selector->candidates($run->ticket, (int) $run->id)->pluck('id')->map('intval')->all() : [];
        $evidence['full_rerun_available'] = ! $evidence['restricted_evidence'] && $request->user()->tokenCan('tickets.rules.full-rerun') && $rerun->availableFor($run, $request->user());

        return response()->json(['data' => $evidence]);
    }

    #[OA\Post(path: '/api/v1/ticket-rule-executions/{run}/actions/{actionResult}/retry', operationId: 'ticketTicketRuleExecutionApiControllerretry', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'actionResult', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.rules.retry'], 'domain-permissions' => 'ticket.rule_retry', 'additional-domain-permissions' => ['ticket.view']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RuleRequestKey')), responses: [new OA\Response(response: 409, description: 'Conflicting retry key or stale draft checksum', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RuleActionRetryReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function retry(Request $request, TicketRuleRun $run, TicketRuleActionResult $actionResult, RetryTicketRuleAction $retry, ExecuteIdempotentTicketRuleApiCommand $receipt, TicketRuleRuntimeGate $gate, TicketRuleEvidenceAccess $access)
    {
        abort_unless((int) $actionResult->run_id === (int) $run->id, 404);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['idempotency_key' => 'required|string|max:191']);
        abort_unless($gate->enabled(), 422, 'Ticket Rule v2 runtime authority is required for action retry.');
        $run->load(['ticket', 'executions.version']);
        abort_unless($run->ticket, 422, 'The original Ticket is unavailable.');
        app(TicketMutationScopeGuard::class)->assert($run->ticket);
        abort_if($access->runIsRestricted($run, $request->user()), 403, 'The execution evidence is restricted.');
        $attempt = TicketRuleApiFailure::run(fn () => $receipt->handle($request->user(), 'retry', $data['idempotency_key'], ['run_id' => $run->id, 'action_result_id' => $actionResult->id], fn () => $retry->handle($run, $actionResult, $request->user())));

        return response()->json(['data' => ['run_id' => $attempt->run_id, 'action_result_id' => $attempt->id, 'attempt_number' => (int) $attempt->attempt_number,
            'retry_of_id' => $attempt->retry_of_id, 'status' => $attempt->status, 'readback_url' => route('api.v1.ticket-rule-executions.show', $run)]]);
    }

    #[OA\Post(path: '/api/v1/ticket-rule-executions/{run}/full-rerun/preview', operationId: 'ticketTicketRuleExecutionApiControllerpreviewRerun', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.rules.full-rerun'], 'domain-permissions' => 'ticket.rule_full_rerun', 'additional-domain-permissions' => ['ticket.view', 'ticket.rule_preview']], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RuleFullRerunPreview')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function previewRerun(Request $request, TicketRuleRun $run, TicketRuleFullRerunBoundary $rerun)
    {
        return response()->json(['data' => TicketRuleApiFailure::run(fn () => $rerun->preview($run, $request->user()))]);
    }

    #[OA\Post(path: '/api/v1/ticket-rule-executions/{run}/full-rerun', operationId: 'ticketTicketRuleExecutionApiControllerrerun', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.rules.full-rerun'], 'domain-permissions' => 'ticket.rule_full_rerun', 'additional-domain-permissions' => ['ticket.view', 'ticket.rule_preview']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RuleFullRerunRequest')), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RuleFullRerunReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function rerun(Request $request, TicketRuleRun $run, TicketRuleFullRerunBoundary $rerun)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['preview_receipt' => 'required|string|max:8192', 'confirm_full_rerun' => 'required|accepted']);
        $result = TicketRuleApiFailure::run(fn () => $rerun->execute($run, $request->user(), $data['preview_receipt']));

        return response()->json(['data' => ['run_id' => $result->run->id, 'status' => $result->terminalStatus, 'source_run_id' => $run->id,
            'readback_url' => route('api.v1.ticket-rule-executions.show', $result->run)]]);
    }
}
