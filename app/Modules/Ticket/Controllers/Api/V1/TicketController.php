<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Ticket\Actions\ChangeTicketStatus;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Actions\SyncExternalTicketMessage;
use App\Modules\Ticket\Actions\UpdateTicketFields;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Resources\Api\V1\TicketResource;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Tickets',
    description: 'API endpoints for ticket automation.'
)]
class TicketController extends Controller
{
    #[OA\Get(
        path: '/api/v1/tickets',
        operationId: 'getTicketList',
        description: 'Returns a paginated list of tickets.',
        summary: 'Get list of tickets',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'client_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'owner_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing tickets.read scope'),
        ]
    )]
    public function index(Request $request, EnsureTicketDefaults $defaults)
    {
        $defaults->handle();

        $query = Ticket::query()
            ->with(['queue', 'status', 'priority', 'client', 'owner'])
            ->latest('updated_at');

        if ($request->filled('q')) {
            $needle = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($needle): void {
                $inner->where('ticket_key', 'like', '%'.$needle.'%')
                    ->orWhere('subject', 'like', '%'.$needle.'%')
                    ->orWhere('description', 'like', '%'.$needle.'%');
            });
        }

        foreach (['client_id', 'status_id', 'queue_id', 'priority_id', 'owner_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->integer($filter));
            }
        }

        if ($request->input('lifecycle', 'all') === 'open') {
            $query->whereHas('status', fn ($statusQuery) => $statusQuery->where('is_closed', false));
        }

        if ($request->input('lifecycle') === 'closed') {
            $query->whereHas('status', fn ($statusQuery) => $statusQuery->where('is_closed', true));
        }

        return TicketResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Get(
        path: '/api/v1/tickets/{ticket}',
        operationId: 'getTicketByKey',
        description: 'Returns one ticket by ticket key.',
        summary: 'Get ticket information',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [
            new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing tickets.read scope'),
            new OA\Response(response: 404, description: 'Ticket not found'),
        ]
    )]
    public function show(Ticket $ticket)
    {
        return new TicketResource($this->loadTicket($ticket));
    }

    #[OA\Post(
        path: '/api/v1/tickets',
        operationId: 'createTicket',
        description: 'Creates a ticket through the ticket engine.',
        summary: 'Create ticket',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['subject'],
                properties: [
                    new OA\Property(property: 'subject', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'client_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'site_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'contact_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'asset_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'owner_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'priority_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'ticket_type_id', type: 'integer', nullable: true),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Ticket created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing tickets.create scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request, StoreTicket $storeTicket)
    {
        $data = $this->validateStorePayload($request);
        $this->validateContext($data);

        if (! empty($data['ticket_type_id'])) {
            $type = TicketType::query()->where('is_active', true)->findOrFail($data['ticket_type_id']);
            $data['type'] = $type->slug;
        }

        $ticket = $storeTicket->handle(array_merge($data, ['channel' => $data['channel'] ?? 'api']), $request->user());

        return (new TicketResource($this->loadTicket($ticket)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/tickets/{ticket}',
        operationId: 'replaceTicket',
        description: 'Updates ticket fields and optionally changes status.',
        summary: 'Update ticket',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [
            new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ticket updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing tickets.update scope'),
            new OA\Response(response: 404, description: 'Ticket not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[OA\Patch(
        path: '/api/v1/tickets/{ticket}',
        operationId: 'patchTicket',
        description: 'Partially updates ticket fields and optionally changes status.',
        summary: 'Partially update ticket',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [
            new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ticket updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing tickets.update scope'),
            new OA\Response(response: 404, description: 'Ticket not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(
        Request $request,
        Ticket $ticket,
        UpdateTicketFields $updateTicketFields,
        ChangeTicketStatus $changeTicketStatus,
        TicketActionGuard $actionGuard
    ) {
        if ($reason = $actionGuard->reason($ticket, TicketAction::UPDATE_FIELDS, $request->user())) {
            throw ValidationException::withMessages(['ticket' => $reason]);
        }

        $data = $this->validateUpdatePayload($request);
        $this->validateContext(array_merge([
            'client_id' => $ticket->client_id,
            'contact_id' => $ticket->contact_id,
        ], $data));

        $fieldData = array_intersect_key($data, array_flip([
            'subject',
            'description',
            'queue_id',
            'priority_id',
            'category_id',
            'owner_id',
            'site_id',
            'asset_id',
        ]));

        if ($fieldData !== []) {
            $updateTicketFields->handle($ticket, $fieldData, $request->user());
        }

        if (! empty($data['status_id']) && (int) $data['status_id'] !== (int) $ticket->refresh()->status_id) {
            $status = TicketStatus::query()->where('is_active', true)->findOrFail($data['status_id']);
            $changeTicketStatus->handle($ticket->refresh(), $status, $request->user());
        }

        return new TicketResource($this->loadTicket($ticket->refresh()));
    }

    #[OA\Post(
        path: '/api/v1/tickets/{ticket}/external-messages',
        operationId: 'syncExternalTicketMessage',
        description: 'Creates or updates an idempotent externally sourced ticket message, such as an N-able MSP Manager comment.',
        summary: 'Sync external ticket message',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [
            new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'External message updated'),
            new OA\Response(response: 201, description: 'External message created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing tickets.update scope'),
            new OA\Response(response: 404, description: 'Ticket not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeExternalMessage(Request $request, Ticket $ticket, SyncExternalTicketMessage $syncExternalTicketMessage)
    {
        [$message, $created] = $syncExternalTicketMessage->handle($ticket, $this->validateExternalMessagePayload($request));

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

    private function validateStorePayload(Request $request): array
    {
        return $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'queue_id' => ['nullable', Rule::exists('ticket_queues', 'id')->where('is_active', true)],
            'status_id' => ['nullable', Rule::exists('ticket_statuses', 'id')->where('is_active', true)],
            'priority_id' => ['nullable', Rule::exists('ticket_priorities', 'id')->where('is_active', true)],
            'category_id' => ['nullable', Rule::exists((new Category())->getTable(), 'id')->where('type', 'ticket')],
            'ticket_type_id' => ['nullable', Rule::exists('ticket_types', 'id')->where('is_active', true)],
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'site_id' => ['nullable', Rule::exists('client_sites', 'id')],
            'contact_id' => ['nullable', Rule::exists('client_users', 'id')],
            'asset_id' => ['nullable', Rule::exists('assets', 'id')],
            'owner_id' => ['nullable', Rule::exists((new User())->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
            'channel' => ['nullable', 'string', 'max:50'],
            'impact' => ['nullable', 'integer', 'min:1', 'max:5'],
            'urgency' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);
    }

    private function validateUpdatePayload(Request $request): array
    {
        return $request->validate([
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'queue_id' => ['sometimes', 'required', Rule::exists('ticket_queues', 'id')->where('is_active', true)],
            'status_id' => ['sometimes', 'required', Rule::exists('ticket_statuses', 'id')->where('is_active', true)],
            'priority_id' => ['sometimes', 'required', Rule::exists('ticket_priorities', 'id')->where('is_active', true)],
            'category_id' => ['sometimes', 'nullable', Rule::exists((new Category())->getTable(), 'id')->where('type', 'ticket')],
            'owner_id' => ['sometimes', 'nullable', Rule::exists((new User())->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
            'site_id' => ['sometimes', 'nullable', Rule::exists('client_sites', 'id')],
            'asset_id' => ['sometimes', 'nullable', Rule::exists('assets', 'id')],
        ]);
    }

    private function validateExternalMessagePayload(Request $request): array
    {
        return $request->validate([
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

            if (! empty($data['client_id']) && (int) $asset->client_id !== (int) $data['client_id']) {
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
        return $ticket->load(['queue', 'status', 'priority', 'client', 'site', 'contact', 'owner', 'asset']);
    }
}
