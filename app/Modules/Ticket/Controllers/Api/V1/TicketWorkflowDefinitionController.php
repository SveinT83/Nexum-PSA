<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowVersion;
use App\Modules\Ticket\Services\TicketWorkflowDefinitionService;
use App\Modules\Ticket\Services\TicketWorkflowMigrationService;
use App\Modules\Ticket\Services\TicketWorkflowRequirementEvaluator;
use App\Modules\Ticket\Support\TicketWorkflowCustomerNotificationPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class TicketWorkflowDefinitionController extends Controller
{
    #[OA\Get(path: '/api/v1/ticket-workflows', operationId: 'ticketTicketWorkflowDefinitionControllerindexGet', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Full workflow administration requires ticket.manage_workflows; publishing and migrations require their independent rights. GET catalog reads persisted email templates and does not initialize defaults.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.workflow.manage'], 'domain-permissions' => 'ticket.manage_workflows', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketWorkflowRecord')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta')], required: ['data', 'meta'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function index(Request $request)
    {
        $paginator = TicketWorkflow::query()->with('publishedVersion')->withCount(['states', 'transitions', 'versions'])
            ->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')
            ->paginate(\App\Modules\Integration\Support\DomainApiRead::pageSize($request));

        return response()->json(['data' => $paginator->items(), 'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(), 'total' => $paginator->total()]]);
    }

    #[OA\Get(path: '/api/v1/ticket-workflow-catalog', operationId: 'ticketTicketWorkflowDefinitionControllercatalogGet', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Full workflow administration requires ticket.manage_workflows; publishing and migrations require their independent rights. GET catalog reads persisted email templates and does not initialize defaults.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.workflow.read'], 'domain-permissions' => 'ticket.manage_workflows', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowCatalog')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function catalog(TicketWorkflowRequirementEvaluator $requirements)
    {
        return response()->json(['data' => [
            'requirements' => $requirements->catalog(),
            'transition_triggers' => \App\Modules\Ticket\Support\TicketAction::transitionTriggerDefinitions(),
            'actions' => \App\Modules\Ticket\Support\TicketAction::definitions(),
            'customer_notification_channels' => TicketWorkflowCustomerNotificationPolicy::channelDefinitions(),
            'customer_notification_email_templates' => EmailTemplate::query()
                ->where('scope', 'tickets')->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')->get(['key', 'name']),
        ]]);
    }

    #[OA\Get(path: '/api/v1/ticket-workflows/{workflow}', operationId: 'ticketTicketWorkflowDefinitionControllershowGet', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Full workflow administration requires ticket.manage_workflows; publishing and migrations require their independent rights. GET catalog reads persisted email templates and does not initialize defaults.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'workflow', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.workflow.manage'], 'domain-permissions' => 'ticket.manage_workflows', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowEditorRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(TicketWorkflow $workflow, TicketWorkflowDefinitionService $definitions)
    {
        return response()->json(['data' => [
            'workflow' => $workflow->load('publishedVersion'),
            'draft_definition' => $definitions->fromWorkflow($workflow),
            'published_definition' => $workflow->publishedVersion?->definition,
        ]]);
    }

    #[OA\Post(path: '/api/v1/ticket-workflows', operationId: 'ticketTicketWorkflowDefinitionControllerstorePost', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Full workflow administration requires ticket.manage_workflows; publishing and migrations require their independent rights. GET catalog reads persisted email templates and does not initialize defaults.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.workflow.manage'], 'domain-permissions' => 'ticket.manage_workflows', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TicketWorkflowWriteRequest')), responses: [new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowEditorRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function store(Request $request, TicketWorkflowDefinitionService $definitions)
    {
        $data = $this->validatePayload($request);
        $workflow = DB::transaction(function () use ($data, $definitions) {
            $workflow = TicketWorkflow::query()->create($data['workflow']);
            $definitions->saveDraft($workflow, $data['definition']);

            return $workflow;
        });

        return $this->show($workflow->refresh(), $definitions)->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/ticket-workflows/{workflow}', operationId: 'ticketTicketWorkflowDefinitionControllerupdatePut', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Full workflow administration requires ticket.manage_workflows; publishing and migrations require their independent rights. GET catalog reads persisted email templates and does not initialize defaults.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'workflow', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.workflow.manage'], 'domain-permissions' => 'ticket.manage_workflows', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TicketWorkflowWriteRequest')), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowEditorRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function update(Request $request, TicketWorkflow $workflow, TicketWorkflowDefinitionService $definitions)
    {
        $data = $this->validatePayload($request, $workflow);
        DB::transaction(function () use ($workflow, $data, $definitions) {
            $workflow = TicketWorkflow::query()->lockForUpdate()->findOrFail($workflow->id);
            $workflow->update($data['workflow']);
            $definitions->saveDraft($workflow, $data['definition']);
        });

        return $this->show($workflow->refresh(), $definitions);
    }

    #[OA\Post(path: '/api/v1/ticket-workflows/{workflow}/publish', operationId: 'ticketTicketWorkflowDefinitionControllerpublishPost', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Full workflow administration requires ticket.manage_workflows; publishing and migrations require their independent rights. GET catalog reads persisted email templates and does not initialize defaults. Each successful publish creates an immutable version; read back versions before repeating an uncertain publish.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'workflow', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.workflow.publish'], 'domain-permissions' => 'ticket.manage_workflows', 'additional-domain-permissions' => ['ticket.workflow_publish']], responses: [new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowVersionRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function publish(Request $request, TicketWorkflow $workflow, TicketWorkflowDefinitionService $definitions)
    {
        abort_unless($request->user()?->can('ticket.workflow_publish'), 403);
        $version = $definitions->publish($workflow, $request->user());

        return response()->json(['data' => $version], 201);
    }

    #[OA\Post(path: '/api/v1/ticket-workflows/{workflow}/migration-preview', operationId: 'ticketTicketWorkflowDefinitionControllermigrationPreviewPost', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Full workflow administration requires ticket.manage_workflows; publishing and migrations require their independent rights. GET catalog reads persisted email templates and does not initialize defaults. Placement is automatic under the native migration policy. state_mapping is accepted for compatibility and does not override placement.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'workflow', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.workflow.manage'], 'domain-permissions' => 'ticket.manage_workflows', 'additional-domain-permissions' => ['ticket.workflow_migrate', 'ticket.view']], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'target_version_id', type: 'integer', nullable: true, description: 'exists:ticket_workflow_versions,id. Context and current policy are validated before writes.'), new OA\Property(property: 'state_mapping', type: 'array', nullable: true, items: new OA\Items(type: 'string', maxLength: 160))])), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowMigrationPreview')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function migrationPreview(Request $request, TicketWorkflow $workflow, TicketWorkflowMigrationService $migrations)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'target_version_id' => ['nullable', 'integer', 'exists:ticket_workflow_versions,id'],
            'state_mapping' => ['nullable', 'array'],
            'state_mapping.*' => ['string', 'max:160'],
        ]);
        $target = ! empty($data['target_version_id'])
            ? TicketWorkflowVersion::query()->findOrFail((int) $data['target_version_id'])
            : $workflow->publishedVersion;

        return response()->json(['data' => $migrations->preview($workflow, $target)]);
    }

    #[OA\Post(path: '/api/v1/ticket-workflows/{workflow}/migrations', operationId: 'ticketTicketWorkflowDefinitionControllermigratePost', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Full workflow administration requires ticket.manage_workflows; publishing and migrations require their independent rights. GET catalog reads persisted email templates and does not initialize defaults. Placement is automatic under the native migration policy. state_mapping is accepted for compatibility and does not override placement.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'workflow', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.workflow.publish'], 'domain-permissions' => 'ticket.manage_workflows', 'additional-domain-permissions' => ['ticket.workflow_migrate', 'ticket.view']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'target_version_id', type: 'integer', description: 'exists:ticket_workflow_versions,id. Context and current policy are validated before writes.'), new OA\Property(property: 'ticket_ids', type: 'array', items: new OA\Items(type: 'integer', description: 'exists:tickets,id. Context and current policy are validated before writes.'), minItems: 1), new OA\Property(property: 'state_mapping', type: 'array', nullable: true, items: new OA\Items(type: 'string', maxLength: 160))], required: ['target_version_id', 'ticket_ids'])), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketWorkflowMigrationResult')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function migrate(Request $request, TicketWorkflow $workflow, TicketWorkflowMigrationService $migrations)
    {
        abort_unless($request->user()?->can('ticket.workflow_migrate'), 403);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'target_version_id' => ['required', 'integer', 'exists:ticket_workflow_versions,id'],
            'ticket_ids' => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['integer', 'exists:tickets,id'],
            'state_mapping' => ['nullable', 'array'],
            'state_mapping.*' => ['string', 'max:160'],
        ]);
        $target = TicketWorkflowVersion::query()->findOrFail((int) $data['target_version_id']);
        Ticket::query()->whereIn('id', $data['ticket_ids'])->get()->each(fn (Ticket $ticket) => app(\App\Modules\Ticket\Services\TicketMutationScopeGuard::class)->assert($ticket));
        $count = $migrations->migrate(
            $workflow,
            $target,
            array_map('intval', $data['ticket_ids']),
            $request->user(),
            ruleEventSourceChannel: 'api',
            ruleEventSourceAction: 'TicketWorkflowDefinitionController.migrate',
        );

        return response()->json(['data' => ['migrated_count' => $count, 'target_version_id' => $target->id, 'placement_mode' => 'automatic']]);
    }

    /** @return array{workflow: array<string, mixed>, definition: array<string, mixed>} */
    private function validatePayload(Request $request, ?TicketWorkflow $workflow = null): array
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:ticket_workflows,slug,'.($workflow?->id ?? 'NULL')],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'definition' => ['required', 'array'],
            'definition.states' => ['required', 'array', 'min:1'],
            'definition.states.*' => ['required', 'array'],
            'definition.states.*.state_key' => ['required', 'string', 'max:160'],
            'definition.states.*.name' => ['required', 'string', 'max:255'],
            'definition.states.*.ticket_status_id' => ['required', 'integer', 'exists:ticket_statuses,id'],
            'definition.states.*.is_initial' => ['sometimes', 'boolean'],
            'definition.states.*.is_terminal' => ['sometimes', 'boolean'],
            'definition.states.*.requirements' => ['nullable', 'array'],
            'definition.states.*.action_policy' => ['nullable', 'array'],
            'definition.states.*.assignment_policy' => ['nullable', 'array'],
            'definition.states.*.commercial_policy' => ['nullable', 'array'],
            'definition.states.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'definition.transitions' => ['nullable', 'array'],
            'definition.transitions.*.transition_key' => ['required', 'string', 'max:160'],
            'definition.transitions.*.from_state_key' => ['required', 'string', 'max:160'],
            'definition.transitions.*.to_state_key' => ['required', 'string', 'max:160'],
            'definition.transitions.*.label' => ['required', 'string', 'max:255'],
            'definition.transitions.*.manual_enabled' => ['sometimes', 'boolean'],
            'definition.transitions.*.trigger_actions' => ['nullable', 'array'],
            'definition.transitions.*.trigger_actions.*' => ['string'],
            'definition.transitions.*.requirements' => ['nullable', 'array'],
            'definition.transitions.*.customer_notification' => ['nullable', 'array'],
            'definition.transitions.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'definition.escalation_paths' => ['nullable', 'array'],
        ]);

        return [
            'workflow' => [
                'name' => $data['name'],
                'slug' => ($data['slug'] ?? null) ?: Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 10),
                'definition_status' => 'draft',
            ],
            'definition' => $data['definition'],
        ];
    }
}
