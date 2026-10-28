<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\PublishTicketToCustomerPortal;
use App\Modules\Ticket\Actions\StoreIdempotentTicketCustomerReply;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Actions\SyncExternalTicketMessage;
use App\Modules\Ticket\Actions\SyncTicketCustomFieldValues;
use App\Modules\Ticket\Actions\TransitionTicketWorkflow;
use App\Modules\Ticket\Actions\UpdateTicketFields;
use App\Modules\Ticket\Exceptions\TicketMessageIdempotencyConflict;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Resources\Api\V1\TicketMessageResource;
use App\Modules\Ticket\Resources\Api\V1\TicketResource;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketReplyContactResolver;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Tickets', description: 'Full operational Ticket API, with separate token and current actor rights.')]
class TicketController extends Controller
{
    #[OA\Get(path: '/api/v1/tickets', operationId: 'getTicketList', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Legacy API defaults include all lifecycle/ownership records; add explicit filters when needed.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 255)), new OA\Parameter(name: 'lifecycle', in: 'query', schema: new OA\Schema(type: 'string', enum: ['all', 'open', 'closed'])), new OA\Parameter(name: 'ownership', in: 'query', schema: new OA\Schema(type: 'string', enum: ['all', 'mine', 'mine_unassigned'])), new OA\Parameter(name: 'unread', in: 'query', schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'unassigned', in: 'query', schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['newest', 'oldest', 'ticket', 'subject', 'client', 'technician', 'queue', 'status', 'updated', 'priority', 'sla_name', 'sla', 'unread'])), new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])), new OA\Parameter(name: 'context_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['client', 'internal'])), new OA\Parameter(name: 'client_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'status_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'queue_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'priority_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'owner_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'site_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'contact_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'asset_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'work_context_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'tag_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketApiRecord')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')], required: ['data', 'meta'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function index(Request $request, EnsureTicketDefaults $defaults)
    {
        \App\Modules\Integration\Support\DomainApiRead::normalizeQueryBooleans($request, ['unread', 'unassigned']);
        $filters = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['q' => 'sometimes|string|max:255', 'lifecycle' => 'sometimes|in:all,open,closed', 'ownership' => 'sometimes|in:all,mine,mine_unassigned',
            'unread' => 'sometimes|boolean', 'unassigned' => 'sometimes|boolean', 'sort' => 'sometimes|in:newest,oldest,ticket,subject,client,technician,queue,status,updated,priority,sla_name,sla,unread', 'direction' => 'sometimes|in:asc,desc',
            'context_type' => 'sometimes|in:client,internal', 'client_id' => 'sometimes|integer|min:1', 'status_id' => 'sometimes|integer|min:1', 'queue_id' => 'sometimes|integer|min:1',
            'priority_id' => 'sometimes|integer|min:1', 'category_id' => 'sometimes|integer|min:1', 'owner_id' => 'sometimes|integer|min:1', 'site_id' => 'sometimes|integer|min:1',
            'contact_id' => 'sometimes|integer|min:1', 'asset_id' => 'sometimes|integer|min:1', 'work_context_id' => 'sometimes|integer|min:1', 'tag_id' => 'sometimes|integer|min:1']);
        // Preserve the existing API defaults while exposing every composable browser filter.
        $filters += ['lifecycle' => 'all', 'ownership' => 'all'];

        return TicketResource::collection(app(\App\Modules\Ticket\Queries\TicketIndexQuery::class)->paginate($filters, \App\Modules\Integration\Support\DomainApiRead::pageSize($request)));
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}', operationId: 'getTicketByKey', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketApiRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(Ticket $ticket)
    {
        app(\App\Modules\Ticket\Services\TicketMutationScopeGuard::class)->assertReadable($ticket);

        return new TicketResource($this->loadTicket($ticket));
    }

    #[OA\Post(path: '/api/v1/tickets', operationId: 'createTicket', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Schedule and portal choices retain their current policy. Custom Field writes are conditional on the API-write gate and current field rights; portal publication additionally requires tickets.portal.publish and ticket.update.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.create'], 'domain-permissions' => 'ticket.create', 'additional-domain-permissions' => [], 'conditional-authorization' => ['customer_portal_visibility=published with a client: tickets.portal.publish and ticket.update.', 'Custom Fields: current API-write switch and field edit rights.']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TicketCreateRequest')), responses: [new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketApiRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function store(Request $request, StoreTicket $storeTicket, SyncTicketCustomFieldValues $syncCustomFields)
    {
        $data = $this->validateStorePayload($request);
        $hasCustomFieldInput = array_key_exists('custom_fields', $data);
        $customFieldValues = (array) ($data['custom_fields'] ?? []);
        unset($data['custom_fields']);

        $this->validateContext($data);
        $data = app(\App\Modules\Ticket\Services\TicketApiPayload::class)->prepare($request, $data);

        if (! empty($data['ticket_type_id'])) {
            $type = TicketType::query()->where('is_active', true)->findOrFail($data['ticket_type_id']);
            $data['type'] = $type->slug;
        }

        $actor = $request->user();
        $ticket = DB::transaction(function () use ($actor, $customFieldValues, $data, $hasCustomFieldInput, $storeTicket, $syncCustomFields): Ticket {
            if (array_key_exists('tag_names', $data)) {
                $data['tag_ids'] = app(\App\Modules\Ticket\Support\TicketFormValues::class)->resolveTagIds($data['tag_names'] ?? []);
            }
            $ticket = $storeTicket->handle(
                array_merge($data, ['channel' => $data['channel'] ?? 'api']),
                $actor,
            );

            if ($hasCustomFieldInput || config('ticket_rules.capabilities.custom_fields.api_write', false)) {
                $syncCustomFields->handle($ticket, $customFieldValues, $actor, 'api', [
                    'operation' => 'create',
                    'require_complete' => true,
                    'source_channel' => 'api',
                    'source_action' => 'Api\\V1\\TicketController.store',
                ]);
            }

            if (($data['customer_portal_visibility'] ?? null) === \App\Modules\Ticket\Support\TicketPortalPolicy::VISIBILITY_PUBLISHED && $ticket->client_id) {
                app(\App\Modules\Ticket\Actions\PublishTicketToCustomerPortal::class)->handle($ticket, $actor);
            }

            return $ticket->refresh();
        });

        return (new TicketResource($this->loadTicket($ticket)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/tickets/{ticket}', operationId: 'replaceTicket', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Schedule and portal choices retain their current policy. Custom Field writes are conditional on the API-write gate and current field rights; portal publication additionally requires tickets.portal.publish and ticket.update.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.update'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => [], 'conditional-authorization' => ['owner change: native ticket.assign policy.', 'status change: current transition and reopen gates.', 'Custom Fields: current API-write switch and field edit rights.']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TicketEditRequest')), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketApiRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    #[OA\Patch(path: '/api/v1/tickets/{ticket}', operationId: 'patchTicket', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Schedule and portal choices retain their current policy. Custom Field writes are conditional on the API-write gate and current field rights; portal publication additionally requires tickets.portal.publish and ticket.update.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.update'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => [], 'conditional-authorization' => ['owner change: native ticket.assign policy.', 'status change: current transition and reopen gates.', 'Custom Fields: current API-write switch and field edit rights.']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TicketEditRequest')), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketApiRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function update(
        Request $request,
        Ticket $ticket,
        UpdateTicketFields $updateTicketFields,
        TransitionTicketWorkflow $transitionWorkflow,
        TicketActionGuard $actionGuard,
        SyncTicketCustomFieldValues $syncCustomFields,
    ) {
        if ($reason = $actionGuard->reason($ticket, TicketAction::UPDATE_FIELDS, $request->user())) {
            throw ValidationException::withMessages(['ticket' => $reason]);
        }

        $data = $this->validateUpdatePayload($request);
        $hasCustomFieldInput = array_key_exists('custom_fields', $data);
        $customFieldValues = (array) ($data['custom_fields'] ?? []);
        unset($data['custom_fields']);

        $this->validateContext(array_merge([
            'client_id' => $ticket->client_id,
            'contact_id' => $ticket->contact_id,
            'site_id' => $ticket->site_id,
            'asset_id' => $ticket->asset_id,
        ], $data));
        $data = app(\App\Modules\Ticket\Services\TicketApiPayload::class)->prepare($request, $data, $ticket);

        $fieldData = array_intersect_key($data, array_flip([
            'subject',
            'description',
            'queue_id',
            'priority_id',
            'category_id',
            'owner_id',
            'site_id',
            'contact_id',
            'asset_id',
            'client_id', 'ticket_type_id', 'impact', 'urgency', 'sla_id',
            'is_scheduled', 'planned_start_at', 'planned_end_at', 'timezone', 'schedule_type',
            'recurrence_rule', 'recurrence_ends_at', 'task_template_group_id', 'sla_mode',
        ]));

        if (array_key_exists('owner_id', $fieldData)
            && (int) ($fieldData['owner_id'] ?? 0) !== (int) ($ticket->owner_id ?? 0)
            && ($reason = $actionGuard->reason($ticket, TicketAction::ASSIGN_OTHER, $request->user()))) {
            throw ValidationException::withMessages(['owner_id' => $reason]);
        }

        $actor = $request->user();
        DB::transaction(function () use (
            $actor,
            $customFieldValues,
            $data,
            $fieldData,
            $hasCustomFieldInput,
            $syncCustomFields,
            $ticket,
            $transitionWorkflow,
            $updateTicketFields,
        ): void {
            if ($fieldData !== []) {
                $updateTicketFields->handle($ticket, $fieldData, $actor);
            }

            if (! empty($data['status_id']) && (int) $data['status_id'] !== (int) $ticket->refresh()->status_id) {
                $status = TicketStatus::query()->where('is_active', true)->findOrFail($data['status_id']);
                $transitionWorkflow->handleToStatus($ticket->refresh(), $status, $actor);
            }

            if (array_key_exists('tag_names', $data)) {
                $tagIds = app(\App\Modules\Ticket\Support\TicketFormValues::class)->resolveTagIds($data['tag_names'] ?? []);
                app(\App\Modules\Ticket\Actions\MutateTicketTags::class)->replace($ticket->refresh(), $tagIds, $actor, ['source_channel' => 'api', 'source_action' => 'TicketController.update']);
            }
            if ($hasCustomFieldInput) {
                $syncCustomFields->handle($ticket->refresh(), $customFieldValues, $actor, 'api', [
                    'operation' => 'update',
                    'source_channel' => 'api',
                    'source_action' => 'Api\\V1\\TicketController.update',
                ]);
            }
        });

        return new TicketResource($this->loadTicket($ticket->refresh()));
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/external-messages', operationId: 'syncExternalTicketMessage', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Inbound synchronization only; source and external_id identify the existing message. It does not author a technician reply.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.update'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TicketExternalMessageRequest')), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketExternalMessageResult'), new OA\Property(property: 'created', type: 'boolean')], required: ['data'])), new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketExternalMessageResult'), new OA\Property(property: 'created', type: 'boolean')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function storeExternalMessage(Request $request, Ticket $ticket, SyncExternalTicketMessage $syncExternalTicketMessage)
    {
        [$message, $created] = $syncExternalTicketMessage->handle(
            $ticket,
            $this->validateExternalMessagePayload($request),
            $request->user(),
        );

        return response()->json([
            'data' => [
                'id' => $message->id,
                'ticket_key' => $ticket->ticket_key,
                'type' => $message->type,
                'visibility' => $message->visibility,
                'subject' => $message->subject,
                'body' => $message->body,
                'metadata' => $message->metadata,
                'created_at' => $message->created_at?->toISOString(),
                'updated_at' => $message->updated_at?->toISOString(),
            ],
            'created' => $created,
        ], $created ? 201 : 200);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/portal-visibility', operationId: 'publishTicketToCustomerPortal', description: 'Uses the same domain actions, current actor rights and object checks as the browser. An eligible published Ticket cannot be unpublished through this action.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.portal.publish'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'portal_visible', type: 'boolean', enum: [true])], required: ['portal_visible'])), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketApiRecord'), new OA\Property(property: 'published_now', type: 'boolean')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function publishPortal(
        Request $request,
        Ticket $ticket,
        PublishTicketToCustomerPortal $publishToPortal,
        TicketReplyContactResolver $contactResolver,
    ) {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['portal_visible' => ['required', 'boolean']]);

        if (! $data['portal_visible']) {
            throw ValidationException::withMessages([
                'portal_visible' => 'Published tickets cannot be unpublished through the API.',
            ]);
        }

        $publishToPortal->authorize($request->user());

        if (! $ticket->client_id || ! $contactResolver->resolve($ticket, $ticket->contact_id)) {
            throw ValidationException::withMessages([
                'contact_id' => 'Portal publication through the API requires an active Ticket contact with an email address in the Ticket client.',
            ]);
        }

        $result = $publishToPortal->handle($ticket, $request->user());

        return response()->json([
            'data' => (new TicketResource($this->loadTicket($result['ticket'])))->resolve($request),
            'published_now' => $result['published_now'],
        ]);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/messages', operationId: 'createTicketCustomerReply', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Actor-bound idempotency includes uploaded file fingerprints. JSON without files or multipart/form-data with attachments[] (maximum five files of 20 MiB each). Customer email acceptance does not prove inbox delivery.', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.reply_customer'], 'domain-permissions' => 'ticket.reply_customer', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: [new OA\JsonContent(ref: '#/components/schemas/TicketCustomerReplyRequest'), new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/TicketCustomerReplyRequest'))]), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketLegacyReplyResult'), new OA\Property(property: 'created', type: 'boolean')], required: ['data'])), new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketLegacyReplyResult'), new OA\Property(property: 'created', type: 'boolean')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function storeMessage(Request $request, Ticket $ticket, StoreIdempotentTicketCustomerReply $storeReply)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'type' => ['sometimes', Rule::in(['customer_reply'])],
            'body' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'reply_intent' => ['nullable', Rule::in([
                TicketAction::CUSTOMER_UPDATE,
                TicketAction::REQUEST_CUSTOMER_INPUT,
                TicketAction::SEND_SOLUTION,
            ])],
            'reply_contact_id' => ['nullable', 'integer', Rule::exists('client_users', 'id')],
            'cc' => ['nullable', 'string', 'max:1000'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:20480'],
        ]);

        $invalidCc = collect(preg_split('/[,;\s]+/', (string) ($data['cc'] ?? '')))
            ->filter()
            ->first(fn ($email) => ! filter_var($email, FILTER_VALIDATE_EMAIL));

        if ($invalidCc) {
            throw ValidationException::withMessages(['cc' => 'Every CC recipient must be a valid email address.']);
        }

        try {
            $result = $storeReply->handle($ticket, $data, $request->user());
        } catch (TicketMessageIdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        $message = $result['message'];
        $message->setRelation('ticket', $ticket);

        return response()->json([
            'data' => [
                'message' => (new TicketMessageResource($message))->resolve($request),
                'ticket' => (new TicketResource($this->loadTicket($ticket->refresh())))->resolve($request),
            ],
            'created' => $result['created'],
        ], $result['created'] ? 201 : 200);
    }

    private function validateStorePayload(Request $request): array
    {
        return \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'workflow_id' => ['sometimes', 'nullable', 'integer', Rule::exists('ticket_workflows', 'id')->whereNotNull('published_version_id')],
            'customer_portal_visibility' => ['sometimes', 'nullable', Rule::in(array_keys(\App\Modules\Ticket\Support\TicketPortalPolicy::visibilityOptions()))],

            'tag_names' => ['sometimes', 'nullable', 'array'],
            'tag_names.*' => ['nullable', 'string', 'max:80'],
            'is_scheduled' => ['sometimes', 'boolean'],
            'planned_start_at' => ['sometimes', 'nullable', 'date'],
            'planned_end_at' => ['sometimes', 'nullable', 'date'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            'schedule_type' => ['sometimes', 'nullable', Rule::in(['one_time', 'recurring'])],
            'recurrence_rule' => ['sometimes', 'nullable', Rule::in(['FREQ=DAILY', 'FREQ=WEEKLY', 'FREQ=MONTHLY'])],
            'recurrence_ends_at' => ['sometimes', 'nullable', 'date'],
            'task_template_group_id' => ['sometimes', 'nullable', 'integer', Rule::exists('task_template_groups', 'id')->where('is_active', true)],
            'sla_mode' => ['sometimes', 'nullable', Rule::in(['defer_until_planned_start', 'non_sla_until_start', 'normal'])],

            'type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'queue_id' => ['nullable', Rule::exists('ticket_queues', 'id')->where('is_active', true)],
            'status_id' => ['nullable', Rule::exists('ticket_statuses', 'id')->where('is_active', true)],
            'priority_id' => ['nullable', Rule::exists('ticket_priorities', 'id')->where('is_active', true)],
            'category_id' => ['nullable', Rule::exists((new Category)->getTable(), 'id')->where(fn ($q) => $q->where('type', Category::TYPE_TICKET)->orWhereNull('type'))->where('is_active', true)],
            'ticket_type_id' => ['nullable', Rule::exists('ticket_types', 'id')->where('is_active', true)],
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'site_id' => ['nullable', Rule::exists('client_sites', 'id')],
            'contact_id' => ['nullable', Rule::exists('client_users', 'id')],
            'asset_id' => ['nullable', Rule::exists('assets', 'id')],
            'owner_id' => ['nullable', Rule::exists((new User)->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
            'channel' => ['nullable', 'string', 'max:50'],
            'impact' => ['nullable', 'integer', 'min:1', 'max:5'],
            'urgency' => ['nullable', 'integer', 'min:1', 'max:5'],
            'custom_fields' => ['sometimes', 'array'],
        ]);
    }

    private function validateUpdatePayload(Request $request): array
    {
        return \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'client_id' => ['sometimes', 'nullable', 'integer', 'exists:clients,id'],
            'ticket_type_id' => ['sometimes', 'required', 'integer', Rule::exists('ticket_types', 'id')->where('is_active', true)],
            'impact' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'urgency' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'sla_id' => ['sometimes', 'required', 'integer', 'exists:sla,id'],

            'tag_names' => ['sometimes', 'nullable', 'array'],
            'tag_names.*' => ['nullable', 'string', 'max:80'],
            'is_scheduled' => ['sometimes', 'boolean'],
            'planned_start_at' => ['sometimes', 'nullable', 'date'],
            'planned_end_at' => ['sometimes', 'nullable', 'date'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            'schedule_type' => ['sometimes', 'nullable', Rule::in(['one_time', 'recurring'])],
            'recurrence_rule' => ['sometimes', 'nullable', Rule::in(['FREQ=DAILY', 'FREQ=WEEKLY', 'FREQ=MONTHLY'])],
            'recurrence_ends_at' => ['sometimes', 'nullable', 'date'],
            'task_template_group_id' => ['sometimes', 'nullable', 'integer', Rule::exists('task_template_groups', 'id')->where('is_active', true)],
            'sla_mode' => ['sometimes', 'nullable', Rule::in(['defer_until_planned_start', 'non_sla_until_start', 'normal'])],

            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'queue_id' => ['sometimes', 'required', Rule::exists('ticket_queues', 'id')->where('is_active', true)],
            'status_id' => ['sometimes', 'required', Rule::exists('ticket_statuses', 'id')->where('is_active', true)],
            'priority_id' => ['sometimes', 'required', Rule::exists('ticket_priorities', 'id')->where('is_active', true)],
            'custom_fields' => ['sometimes', 'array'],
            'category_id' => ['sometimes', 'nullable', Rule::exists((new Category)->getTable(), 'id')->where(fn ($q) => $q->where('type', Category::TYPE_TICKET)->orWhereNull('type'))->where('is_active', true)],
            'owner_id' => ['sometimes', 'nullable', Rule::exists((new User)->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
            'site_id' => ['sometimes', 'nullable', Rule::exists('client_sites', 'id')],
            'contact_id' => ['sometimes', 'nullable', Rule::exists('client_users', 'id')],
            'asset_id' => ['sometimes', 'nullable', Rule::exists('assets', 'id')],
        ]);
    }

    private function validateExternalMessagePayload(Request $request): array
    {
        return \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'source' => ['required', 'string', 'max:100'],
            'external_id' => ['required', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(['internal_note', 'customer_reply'])],
            'visibility' => ['nullable', Rule::in(['internal', 'public'])],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'author_email' => ['nullable', 'email', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);
    }

    private function validateContext(array $data): void
    {
        if (! empty($data['contact_id'])) {
            $contact = ClientUser::query()->with('site')->findOrFail($data['contact_id']);
            $clientId = $data['client_id'] ?? $contact->site?->client_id;

            if ((int) $contact->site?->client_id !== (int) $clientId) {
                throw ValidationException::withMessages([
                    'contact_id' => 'The selected contact does not belong to the selected client.',
                ]);
            }
        }

        if (! empty($data['site_id']) && ! empty($data['client_id'])) {
            $siteBelongsToClient = ClientSite::query()
                ->whereKey($data['site_id'])
                ->where('client_id', $data['client_id'])
                ->exists();

            if (! $siteBelongsToClient) {
                throw ValidationException::withMessages([
                    'site_id' => 'The selected site does not belong to the selected client.',
                ]);
            }
        }

        if (! empty($data['asset_id'])) {
            $asset = Asset::query()->findOrFail($data['asset_id']);
            $clientId = $data['client_id'] ?? null;

            if ($asset->client_id && ! $clientId) {
                throw ValidationException::withMessages([
                    'asset_id' => 'Select the asset client before selecting this asset.',
                ]);
            }

            if (! $asset->client_id && $clientId) {
                throw ValidationException::withMessages([
                    'asset_id' => 'Internal assets cannot be attached to client-scoped tickets.',
                ]);
            }

            if ($asset->client_id && (int) $asset->client_id !== (int) $clientId) {
                throw ValidationException::withMessages([
                    'asset_id' => 'The selected asset does not belong to the selected client.',
                ]);
            }

            if (! empty($data['site_id']) && $asset->site_id && (int) $asset->site_id !== (int) $data['site_id']) {
                throw ValidationException::withMessages([
                    'asset_id' => 'The selected asset does not belong to the selected site.',
                ]);
            }
        }
    }

    private function loadTicket(Ticket $ticket): Ticket
    {
        return $ticket->load(['queue', 'status', 'priority', 'client', 'workContext', 'site', 'contact', 'owner', 'asset', 'workflow', 'workflowVersion', 'tags']);
    }
}
