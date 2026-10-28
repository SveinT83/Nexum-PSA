<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\DomainApiAuthorization;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Ticket\Actions\ExecuteIdempotentTicketRuleApiCommand;
use App\Modules\Ticket\Actions\LegacyTicketRuleMutationBoundary;
use App\Modules\Ticket\Actions\PublishTicketRuleDraft;
use App\Modules\Ticket\Actions\SaveTicketRuleDraft;
use App\Modules\Ticket\Actions\SetPublishedTicketRuleEnabled;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Queries\TicketRuleAdminIndexQuery;
use App\Modules\Ticket\Resources\Api\V1\TicketRuleApiResource;
use App\Modules\Ticket\Resources\Api\V1\TicketRuleVersionApiResource;
use App\Modules\Ticket\Services\TicketLegacyRuleForm;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Services\TicketRuleApiFailure;
use App\Modules\Ticket\Services\TicketRuleBuilderCatalog;
use App\Modules\Ticket\Services\TicketRuleBuilderPreviewPresenter;
use App\Modules\Ticket\Services\TicketRuleDefinitionCanonicalizer;
use App\Modules\Ticket\Services\TicketRulePreviewService;
use App\Modules\Ticket\Services\TicketRulePublicationTargetValidator;
use App\Modules\Ticket\Services\TicketRuleRuntimeGate;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/** Rule API adapters retain native draft/publication authority and never change runtime switches. */
class TicketRuleApiController extends Controller
{
    #[OA\Get(path: '/api/v1/ticket-rules', operationId: 'ticketTicketRuleApiControllerindex', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.rules.read'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketRuleReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function index(Request $request, TicketRuleAdminIndexQuery $query)
    {
        \App\Modules\Integration\Support\DomainApiInput::validate($request, ['search' => 'nullable|string|max:150', 'state' => 'nullable|in:active,inactive,draft', 'lifecycle' => 'nullable|in:legacy,published,disabled', 'sort' => 'nullable|in:name,weight,published_at,draft_updated_at,updated_at', 'direction' => 'nullable|in:asc,desc']);

        return TicketRuleApiResource::collection($query->get($request, DomainApiRead::pageSize($request)));
    }

    #[OA\Get(path: '/api/v1/ticket-rules/{rule}', operationId: 'ticketTicketRuleApiControllershow', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.rules.read'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRuleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(TicketRule $rule)
    {
        return new TicketRuleApiResource($rule);
    }

    #[OA\Get(path: '/api/v1/ticket-rules/readiness', operationId: 'ticketTicketRuleApiControllerreadiness', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.rules.read'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRuleReadiness')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function readiness(TicketRuleRuntimeGate $gate)
    {
        $actorReady = true;
        try {
            $gate->requireExistingActor();
        } catch (\RuntimeException) {
            $actorReady = false;
        }

        return response()->json(['data' => ['capability_enabled' => (bool) config('ticket_rules.v2_enabled', false), 'runtime_enabled' => $gate->enabled(),
            'protected_actor_ready' => $actorReady, 'full_rerun_enabled' => (bool) config('ticket_rules.full_rerun_enabled', false)]]);
    }

    #[OA\Get(path: '/api/v1/ticket-rules/context/{catalog}', operationId: 'ticketTicketRuleApiControllercatalog', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'catalog', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.rules.read'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RuleCatalogRow')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function catalog(Request $request, string $catalog, TicketRuleBuilderCatalog $builder)
    {
        abort_unless(in_array($catalog, ['triggers', 'facts', 'action_fields', 'actions', 'workflow_transitions', 'references', 'custom_fields', 'tickets'], true), 404);
        $all = TicketRuleApiFailure::run(fn () => $builder->get());
        $rows = $all[$catalog];
        if ($catalog === 'references') {
            $rows = collect($rows)->map(fn ($options, $key) => ['key' => $key, 'options' => $options])->values()->all();
        }

        return JsonResource::collection(DomainApiRead::paginateCollection(collect($rows), $request));
    }

    #[OA\Get(path: '/api/v1/ticket-rules/{rule}/draft', operationId: 'ticketTicketRuleApiControllerdraft', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.rules.read'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRuleDraftReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function draft(Request $request, TicketRule $rule, TicketRuleDefinitionCanonicalizer $canonicalizer)
    {
        $definition = data_get($rule->draft_payload_json, 'definition');
        $name = data_get($rule->draft_payload_json, 'name', $rule->name);
        $description = data_get($rule->draft_payload_json, 'description', $rule->description);
        if (! is_array($definition)) {
            $rule->load('publishedVersion');
            $definition = $rule->publishedVersion?->definition_json;
            if (! is_array($definition)) {
                $inspection = $canonicalizer->inspect($rule);
                $definition = $inspection['definition'] ?? ['legacy_conditions' => $rule->conditions_json, 'legacy_actions' => $rule->actions_json];
            }
        }
        $this->assertDefinition($request, $definition);

        return response()->json(['data' => ['rule_id' => $rule->id, 'name' => $name, 'description' => $description, 'definition' => app(\App\Modules\Ticket\Services\TicketRuleApiDefinition::class)->project($definition), 'source_definition_checksum' => \App\Modules\Ticket\Support\TicketRuleStableJson::checksum($definition),
            'draft_checksum' => $rule->draft_checksum, 'expected_no_draft' => $rule->draft_checksum === null]]);
    }

    #[OA\Post(path: '/api/v1/ticket-rules/drafts', operationId: 'ticketTicketRuleApiControllerstoreDraft', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.rules.manage'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RuleDraftCreateRequest')), responses: [new OA\Response(response: 409, description: 'Conflicting retry key or stale draft checksum', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRuleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function storeDraft(Request $request, SaveTicketRuleDraft $save)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['creation_token' => 'required|uuid', 'name' => 'required|string|max:150', 'description' => 'nullable|string|max:10000', 'definition' => 'required|array']);
        $data['definition'] = app(\App\Modules\Ticket\Services\TicketRuleApiDefinition::class)->restore($data['definition'], null);
        $this->assertDefinition($request, $data['definition']);
        $rule = TicketRuleApiFailure::run(fn () => $save->handle(null, ['name' => $data['name'], 'description' => $data['description'] ?? null, 'definition' => $data['definition']], $request->user(), null, false, $data['creation_token']));

        return new TicketRuleApiResource($rule);
    }

    #[OA\Put(path: '/api/v1/ticket-rules/{rule}/draft', operationId: 'ticketTicketRuleApiControllersaveDraft', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.rules.manage'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RuleDraftUpdateRequest')), responses: [new OA\Response(response: 409, description: 'Conflicting retry key or stale draft checksum', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRuleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function saveDraft(Request $request, TicketRule $rule, SaveTicketRuleDraft $save)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['name' => 'required|string|max:150', 'description' => 'nullable|string|max:10000', 'definition' => 'required|array', 'expected_draft_checksum' => 'nullable|string|size:64', 'expected_no_draft' => 'required|boolean', 'source_definition_checksum' => 'nullable|string|size:64']);
        abort_unless($data['expected_no_draft'] || ! empty($data['expected_draft_checksum']), 422, 'Provide the current draft checksum.');
        $saved = DB::transaction(function () use ($request, $rule, $save, $data) {
            \App\Modules\Ticket\Models\TicketRuleAuthorityFence::query()->whereKey(\App\Modules\Ticket\Models\TicketRuleAuthorityFence::SCOPE)->lockForUpdate()->firstOrFail();
            $current = TicketRule::query()->with('publishedVersion')->lockForUpdate()->findOrFail($rule->id);
            $source = data_get($current->draft_payload_json, 'definition') ?? $current->publishedVersion?->definition_json;
            if (! is_array($source)) {
                $inspection = app(TicketRuleDefinitionCanonicalizer::class)->inspect($current);
                $source = $inspection['definition'] ?? null;
            }
            $definition = app(\App\Modules\Ticket\Services\TicketRuleApiDefinition::class)->restore($data['definition'], $source, $data['source_definition_checksum'] ?? null);
            $this->assertDefinition($request, $definition);

            return TicketRuleApiFailure::run(fn () => $save->handle($current, ['name' => $data['name'], 'description' => $data['description'] ?? null, 'definition' => $definition], $request->user(), $data['expected_draft_checksum'] ?? null, (bool) $data['expected_no_draft']));
        });

        return new TicketRuleApiResource($saved);
    }

    #[OA\Post(path: '/api/v1/ticket-rules/preview', operationId: 'ticketTicketRuleApiControllerpreview', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.rules.preview'], 'domain-permissions' => 'ticket.rule_preview', 'additional-domain-permissions' => ['ticket.view', 'ticket.manage_rules']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RulePreviewRequest')), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRulePreviewReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function preview(Request $request, TicketRulePreviewService $preview, TicketRuleBuilderPreviewPresenter $presenter)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['ticket_id' => 'required|integer', 'definition' => 'required|array', 'context' => 'sometimes|array']);
        $ticket = Ticket::query()->findOrFail($data['ticket_id']);
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $this->assertDefinition($request, $data['definition']);
        $result = TicketRuleApiFailure::run(function () use ($preview, $ticket, $data, $request) {
            $result = $preview->draft($ticket, $data['definition'], $request->user(), $data['context'] ?? []);
            if (($data['definition']['trigger'] ?? null) === 'ticket.created') {
                $result['queue_preview'] = $preview->created($ticket, ['channel' => $ticket->channel, 'subject' => $ticket->subject, 'description' => $ticket->description, '_source_action' => 'TicketRuleApiPublishedQueuePreview'], $request->user());
                $result['queue_preview_scope'] = 'published_rules_only';
            }

            return $result;
        });

        return response()->json(['data' => $presenter->present($result)]);
    }

    #[OA\Get(path: '/api/v1/ticket-rules/{rule}/versions', operationId: 'ticketTicketRuleApiControllerversions', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.rules.read'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketRuleVersionReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function versions(Request $request, TicketRule $rule)
    {
        $versions = $rule->versions()->orderByDesc('version_number')->paginate(DomainApiRead::pageSize($request))->withQueryString();
        foreach ($versions as $version) {
            $this->assertDefinition($request, (array) $version->definition_json);
        }

        return TicketRuleVersionApiResource::collection($versions);
    }

    #[OA\Get(path: '/api/v1/ticket-rules/{rule}/versions/{version}', operationId: 'ticketTicketRuleApiControllerversion', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.rules.read'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRuleVersionReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function version(Request $request, TicketRule $rule, TicketRuleVersion $version)
    {
        abort_unless((int) $version->ticket_rule_id === (int) $rule->id, 404);
        $this->assertDefinition($request, (array) $version->definition_json);

        return new TicketRuleVersionApiResource($version);
    }

    #[OA\Post(path: '/api/v1/ticket-rules/{rule}/publish', operationId: 'ticketTicketRuleApiControllerpublish', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.rules.publish'], 'domain-permissions' => 'ticket.rule_publish', 'additional-domain-permissions' => ['ticket.manage_rules']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RulePublishRequest')), responses: [new OA\Response(response: 409, description: 'Conflicting retry key or stale draft checksum', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRuleVersionReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function publish(Request $request, TicketRule $rule, PublishTicketRuleDraft $publish, ExecuteIdempotentTicketRuleApiCommand $receipt)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['expected_draft_checksum' => 'required|string|size:64', 'idempotency_key' => 'required|string|max:191']);
        abort_unless((bool) config('ticket_rules.v2_enabled', false), 422, 'Ticket Rule v2 capability is disabled.');
        TicketRuleApiFailure::run(fn () => app(TicketRuleRuntimeGate::class)->requireExistingActor());
        $version = TicketRuleApiFailure::run(fn () => $receipt->handle($request->user(), 'publish', $data['idempotency_key'], ['rule_id' => $rule->id, 'checksum' => $data['expected_draft_checksum']], fn () => $publish->handle($rule, $request->user(), $data['expected_draft_checksum'])));
        foreach (array_merge((array) data_get($version->definition_json, 'then_actions', []), (array) data_get($version->definition_json, 'else_actions', [])) as $action) {
            $provider = app(\App\Modules\Ticket\Support\TicketRuleActionProviderRegistry::class)->definition($action['type'] ?? null);
            abort_unless($provider && $request->user()->can((string) $provider['publication_permission']), 403);
        }
        $this->assertDefinition($request, (array) $version->definition_json);

        return new TicketRuleVersionApiResource($version);
    }

    #[OA\Post(path: '/api/v1/ticket-rules/legacy', operationId: 'ticketTicketRuleApiControllerlegacyStore', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.rules.manage'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RuleLegacyWriteRequest')), responses: [new OA\Response(response: 409, description: 'Conflicting retry key or stale draft checksum', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRuleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 201, description: 'Record created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRuleReadback')]))])]
    public function legacyStore(Request $request, TicketLegacyRuleForm $form, LegacyTicketRuleMutationBoundary $legacy, ExecuteIdempotentTicketRuleApiCommand $receipt)
    {
        $key = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['idempotency_key' => 'required|string|max:191'])['idempotency_key'];
        $data = $form->validatedRule($request);
        $legacy->authorizeActions($request->user(), $data['actions_json']);
        $rule = TicketRuleApiFailure::run(fn () => $receipt->handle($request->user(), 'legacy-create', $key, $data, fn () => $legacy->create($request->user(), $data + ['trigger' => TicketRule::TRIGGER_CREATE, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id])));

        return new TicketRuleApiResource($rule);
    }

    #[OA\Put(path: '/api/v1/ticket-rules/{rule}/legacy', operationId: 'ticketTicketRuleApiControllerlegacyUpdate', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.rules.manage'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RuleLegacyWriteRequest')), responses: [new OA\Response(response: 409, description: 'Conflicting retry key or stale draft checksum', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRuleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function legacyUpdate(Request $request, TicketRule $rule, TicketLegacyRuleForm $form, LegacyTicketRuleMutationBoundary $legacy)
    {
        return new TicketRuleApiResource(TicketRuleApiFailure::run(fn () => $legacy->update($request->user(), $rule, $form->validatedRule($request) + ['updated_by' => $request->user()->id])));
    }

    #[OA\Put(path: '/api/v1/ticket-rules/{rule}/enabled', operationId: 'ticketTicketRuleApiControllerenabled', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.rules.manage'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RuleEnabledRequest')), responses: [new OA\Response(response: 409, description: 'Conflicting retry key or stale draft checksum', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRuleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function enabled(Request $request, TicketRule $rule, SetPublishedTicketRuleEnabled $published, LegacyTicketRuleMutationBoundary $legacy, ExecuteIdempotentTicketRuleApiCommand $receipt)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['is_active' => 'required|boolean', 'expected_enabled' => 'required|boolean', 'published_version_id' => 'nullable|integer|min:1', 'definition_checksum' => 'nullable|string|size:64', 'idempotency_key' => 'required|string|max:191']);
        $rule->load('publishedVersion');
        if ((int) $rule->publishedVersion?->definition_schema_version === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
            app(DomainApiAuthorization::class)->authorize($request, 'ticket.rule_publish');
            abort_unless($request->user()->tokenCan('tickets.rules.publish'), 403);
            abort_unless(app(TicketRuleRuntimeGate::class)->enabled(), 422, 'Ticket Rule v2 runtime authority is required.');
            \App\Modules\Integration\Support\DomainApiInput::validate($request, ['published_version_id' => 'required|integer|min:1', 'definition_checksum' => 'required|string|size:64']);
        }
        $result = TicketRuleApiFailure::run(fn () => $receipt->handle($request->user(), 'enabled', $data['idempotency_key'], $data + ['rule_id' => $rule->id], function () use ($rule, $request, $data, $published, $legacy) {
            return DB::transaction(function () use ($rule, $request, $data, $published, $legacy) {
                $locked = TicketRule::query()->with('publishedVersion')->lockForUpdate()->findOrFail($rule->id);
                abort_unless((bool) $locked->is_active === (bool) $data['expected_enabled'], 422, 'The enabled state changed. Reload before changing it.');
                if ((bool) $locked->is_active === (bool) $data['is_active']) {
                    return $locked;
                }
                if ((int) $locked->publishedVersion?->definition_schema_version === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
                    abort_unless($request->user()->tokenCan('tickets.rules.publish'), 403);

                    return $published->handle($locked, $request->user(), (int) $data['published_version_id'], $data['definition_checksum'], (bool) $data['expected_enabled']);
                }

                return $legacy->toggle($request->user(), $locked);
            });
        }));

        return new TicketRuleApiResource($result);
    }

    #[OA\Delete(path: '/api/v1/ticket-rules/{rule}', operationId: 'ticketTicketRuleApiControllerdestroy', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.rules.manage'], 'domain-permissions' => 'ticket.manage_rules', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RuleRequestKey')), responses: [new OA\Response(response: 409, description: 'Conflicting retry key or stale draft checksum', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketRuleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function destroy(Request $request, TicketRule $rule, LegacyTicketRuleMutationBoundary $legacy, ExecuteIdempotentTicketRuleApiCommand $receipt)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['idempotency_key' => 'required|string|max:191']);
        $result = TicketRuleApiFailure::run(fn () => $receipt->handle($request->user(), 'delete', $data['idempotency_key'], ['rule_id' => $rule->id], function () use ($legacy, $request, $rule) {
            $legacy->delete($request->user(), $rule);

            return $rule->fresh();
        }));

        return new TicketRuleApiResource($result);
    }

    private function assertDefinition(Request $request, array $definition): void
    {
        try {
            app(TicketRulePublicationTargetValidator::class)->validateCustomFieldAccess($definition, $request->user());
        } catch (\Illuminate\Validation\ValidationException) {
            abort(403, 'The rule references a Custom Field unavailable to your account.');
        }
    }
}
