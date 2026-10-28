<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\DomainApiAuthorization;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Task\Resources\Api\V1\TaskSummaryResource;
use App\Modules\Task\Services\TaskApiScope;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Queries\TicketApiReferenceQuery;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use App\Modules\Ticket\Resources\Api\V1\TicketCostEntryResource;
use App\Modules\Ticket\Resources\Api\V1\TicketPlannedLineResource;
use App\Modules\Ticket\Resources\Api\V1\TicketTimeEntryResource;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Read-only projections are independent of the browser detail action's automatic read receipts. */
final class TicketReadbackController extends Controller
{
    #[OA\Get(path: '/api/v1/tickets/capabilities', operationId: 'ticketParityCapabilities', summary: 'Current registered Ticket parity operations and actor/token eligibility', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.read'], 'domain-permissions' => 'ticket.view|ticket.create|ticket.update', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ParityCapabilities')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function capabilities(Request $request)
    {
        return response()->json(['data' => DomainApiRead::capabilities($request, 'ticket')]);
    }

    #[OA\Get(path: '/api/v1/tickets/context', operationId: 'ticketParityContext', summary: 'Ticket form context and permitted catalog links', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.read'], 'domain-permissions' => 'ticket.view|ticket.create|ticket.update', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ParityFormContext')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function context(Request $request)
    {
        return response()->json(['data' => ['catalogs' => collect(TicketApiReferenceQuery::CATALOGS)->mapWithKeys(fn ($catalog) => [$catalog => route('api.v1.tickets.context.options', ['catalog' => $catalog])]),
            'limits' => ['default_per_page' => 25, 'maximum_per_page' => 100],
            'context_fields' => ['client_id', 'site_id', 'contact_id'],
        ]]);
    }

    #[OA\Get(path: '/api/v1/tickets/context/{catalog}', operationId: 'ticketParityOptions', summary: 'Bounded Ticket form reference catalog', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'catalog', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)), new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'client_id', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'site_id', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'contact_id', in: 'query', schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.read'], 'domain-permissions' => 'ticket.view|ticket.create|ticket.update', 'additional-domain-permissions' => [], 'conditional-authorization' => ['storage-items catalog: tickets.costs.read and ticket.view.']], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection with bounded pagination', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ParityReferenceOption')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function options(Request $request, string $catalog, TicketApiReferenceQuery $query)
    {
        if ($catalog === 'storage-items') {
            app(DomainApiAuthorization::class)->authorize($request, 'ticket.view');
            abort_unless($request->user()->tokenCan('tickets.costs.read'), 403, 'Ticket cost read ability is required.');
        }

        return \Illuminate\Http\Resources\Json\JsonResource::collection($query->paginate($request, $catalog));
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/context', operationId: 'ticketParityRecordContext', summary: 'Ticket context and workflow action decisions', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRecordApiContext')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function recordContext(Request $request, Ticket $ticket, TicketActionGuard $guard)
    {
        $this->assert($ticket);

        return response()->json(['data' => [
            'ticket_id' => $ticket->id, 'ticket_key' => $ticket->ticket_key,
            'client_id' => $ticket->client_id, 'site_id' => $ticket->site_id, 'contact_id' => $ticket->contact_id,
            'work_context_id' => $ticket->work_context_id,
            'actions' => $guard->decisionMap($ticket, $request->user()),
            'catalogs' => collect(TicketApiReferenceQuery::CATALOGS)->mapWithKeys(fn ($catalog) => [$catalog => route('api.v1.tickets.context.options', array_filter(['catalog' => $catalog, 'client_id' => $ticket->client_id, 'site_id' => $ticket->site_id, 'contact_id' => $ticket->contact_id], fn ($v) => $v !== null))]),
        ]]);
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/time-rate-options', operationId: 'ticketParityRates', summary: 'Bounded eligible Ticket rates', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.time.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection with bounded pagination', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketTimeRateOptionReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function rates(Request $request, Ticket $ticket, TicketTimeRateOptions $rates)
    {
        $this->assert($ticket);

        return \Illuminate\Http\Resources\Json\JsonResource::collection(DomainApiRead::paginateCollection($rates->forTicket($ticket), $request));
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/activities', operationId: 'ticketParityActivities', summary: 'Ticket event metadata without restricted event payloads', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection with bounded pagination', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketEventReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function activities(Request $request, Ticket $ticket)
    {
        $this->assert($ticket);

        // Rule/Custom Field event payloads may contain restricted values; metadata stays excluded.
        return \Illuminate\Http\Resources\Json\JsonResource::collection($ticket->events()->orderByDesc('id')->paginate(DomainApiRead::pageSize($request), ['id', 'ticket_id', 'actor_id', 'type', 'created_at'])->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/time-entries', operationId: 'ticketParityTimeEntries', summary: 'Ticket time and billing read-back', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.time.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection with bounded pagination', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketTimeEntryReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function timeEntries(Request $request, Ticket $ticket)
    {
        $this->assert($ticket);

        return TicketTimeEntryResource::collection($ticket->timeEntries()->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/time-entries/{timeEntry}', operationId: 'ticketParityTimeEntry', summary: 'One exact Ticket time record', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'timeEntry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.time.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketTimeEntryReadback')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function timeEntry(Ticket $ticket, TicketTimeEntry $timeEntry)
    {
        $this->assert($ticket);
        abort_unless((int) $timeEntry->ticket_id === (int) $ticket->id, 404);

        return new TicketTimeEntryResource($timeEntry);
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/cost-entries', operationId: 'ticketParityCostEntries', summary: 'Ticket cost and reservation read-back', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.costs.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection with bounded pagination', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketCostEntryReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function costEntries(Request $request, Ticket $ticket)
    {
        $this->assert($ticket);

        return TicketCostEntryResource::collection($ticket->costEntries()->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/cost-entries/{costEntry}', operationId: 'ticketParityCostEntry', summary: 'One exact Ticket cost record', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'costEntry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.costs.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketCostEntryReadback')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function costEntry(Ticket $ticket, TicketCostEntry $costEntry)
    {
        $this->assert($ticket);
        abort_unless((int) $costEntry->ticket_id === (int) $ticket->id, 404);

        return new TicketCostEntryResource($costEntry);
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/planned-lines', operationId: 'ticketParityPlannedLines', summary: 'Ticket planned scope read-back', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.costs.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection with bounded pagination', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketPlannedLineReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function plannedLines(Request $request, Ticket $ticket)
    {
        $this->assert($ticket);

        return TicketPlannedLineResource::collection($ticket->plannedLines()->orderBy('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/tasks', operationId: 'ticketParityTasks', summary: 'Visible Tasks belonging to this Ticket', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tasks.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => ['task.view']], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection with bounded pagination', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskSummaryReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function tasks(Request $request, Ticket $ticket, TaskApiScope $scope)
    {
        $this->assert($ticket);
        app(DomainApiAuthorization::class)->authorize($request, 'task.view');

        return TaskSummaryResource::collection($scope->visible($ticket->tasks()->getQuery(), $request->user())->orderBy('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/schedule', operationId: 'ticketParitySchedule', summary: 'Current Ticket schedule or null', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketScheduleReadback', nullable: true)])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function schedule(Ticket $ticket)
    {
        $this->assert($ticket);
        $schedule = $ticket->schedule()->first();

        return response()->json(['data' => $schedule ? Arr::only($schedule->attributesToArray(), ['id', 'ticket_id', 'task_template_group_id', 'schedule_type', 'planned_start_at', 'planned_end_at', 'timezone', 'recurrence_rule', 'recurrence_ends_at', 'calendar_event_id', 'sla_mode', 'status', 'created_at', 'updated_at']) : null]);
    }

    private function assert(Ticket $ticket): void
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);
    }
}
