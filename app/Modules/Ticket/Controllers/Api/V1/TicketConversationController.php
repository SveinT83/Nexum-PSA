<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAuthorization;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Ticket\Actions\MarkTicketMessageSolution;
use App\Modules\Ticket\Actions\StoreIdempotentTicketApiNote;
use App\Modules\Ticket\Exceptions\TicketMessageIdempotencyConflict;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Resources\Api\V1\TicketAttachmentResource;
use App\Modules\Ticket\Resources\Api\V1\TicketConversationResource;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/** Explicit content grant; the pre-existing metadata-only messages route stays unchanged. */
final class TicketConversationController extends Controller
{
    #[OA\Get(path: '/api/v1/tickets/{ticket}/conversation', operationId: 'ticketParityIndex', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.messages.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted or read-back Ticket content', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketConversationMessage')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function index(Request $request, Ticket $ticket)
    {
        $this->assert($ticket);

        return TicketConversationResource::collection($ticket->messages()->with('fileAttachments.ticket')->orderBy('id')
            ->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/conversation/{message}', operationId: 'ticketParityShow', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.messages.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted or read-back Ticket content', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketConversationMessage')])), new OA\Response(response: 401, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(Ticket $ticket, TicketMessage $message)
    {
        $this->assert($ticket, $message);

        return new TicketConversationResource($message->load('fileAttachments.ticket'));
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/attachments', operationId: 'ticketParityAttachments', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.messages.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted or read-back Ticket content', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketAttachmentReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function attachments(Request $request, Ticket $ticket)
    {
        $this->assert($ticket);

        return TicketAttachmentResource::collection($ticket->attachments()->whereHas('message', fn ($q) => $q->where('ticket_id', $ticket->id))
            ->with('ticket')->orderBy('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/attachments/{attachment}', operationId: 'ticketParityAttachment', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'attachment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.messages.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted or read-back Ticket content', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketAttachmentReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function attachment(Ticket $ticket, TicketAttachment $attachment)
    {
        $this->assertAttachment($ticket, $attachment);

        return new TicketAttachmentResource($attachment->load('ticket'));
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/attachments/{attachment}/download', operationId: 'ticketParityDownload', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'attachment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.attachments.download'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Attachment download', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))), new OA\Response(response: 401, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function download(Ticket $ticket, TicketAttachment $attachment)
    {
        $this->assertAttachment($ticket, $attachment);
        $disk = Storage::disk($attachment->disk ?: 'local');
        abort_unless($attachment->path && $disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->filename);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/internal-notes', operationId: 'ticketParityStoreNote', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.notes.create'], 'domain-permissions' => 'ticket.note_internal', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(type: 'object', required: ['body', 'idempotency_key'], properties: [new OA\Property(property: 'body', type: 'string'), new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 100), new OA\Property(property: 'notify_user_id', type: 'integer', nullable: true), new OA\Property(property: 'mark_as_solution', type: 'boolean'), new OA\Property(property: 'attachments', type: 'array', maxItems: 5, items: new OA\Items(type: 'string', format: 'binary'))]))), responses: [new OA\Response(response: 200, description: 'Persisted or read-back Ticket content', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketConversationMessage')])), new OA\Response(response: 201, description: 'Note created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketConversationMessage')])), new OA\Response(response: 401, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function storeNote(Request $request, Ticket $ticket, StoreIdempotentTicketApiNote $store)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $this->assert($ticket);
        // Browser note creation also passes its explicit message-route permission.
        app(DomainApiAuthorization::class)->authorize($request, 'ticket.reply_customer');
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'body' => ['required', 'string'], 'idempotency_key' => ['required', 'string', 'max:100'],
            'notify_user_id' => ['nullable', 'integer', Rule::exists((new User)->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
            'mark_as_solution' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:5'], 'attachments.*' => ['file', 'max:20480'],
        ]);
        try {
            $result = $store->handle($ticket, $data, $request->user());
        } catch (TicketMessageIdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return (new TicketConversationResource($result['message']->load('fileAttachments.ticket')))
            ->additional(['created' => $result['created']])->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/messages/{message}/solution', operationId: 'ticketParitySolution', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.solutions.mark'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted or read-back Ticket content', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketConversationMessage')])), new OA\Response(response: 401, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership, validation or retry conflict', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function solution(Request $request, Ticket $ticket, TicketMessage $message, MarkTicketMessageSolution $mark)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $this->assert($ticket, $message);
        $result = DB::transaction(function () use ($request, $ticket, $message, $mark) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $message = TicketMessage::query()->where('ticket_id', $ticket->id)->findOrFail($message->id);
            // Repeating the same marker does not advance workflow or duplicate audit events.
            if (($message->metadata['is_solution'] ?? false) !== true) {
                if ($reason = app(TicketActionGuard::class)->reason($ticket, TicketAction::SEND_SOLUTION, $request->user())) {
                    throw ValidationException::withMessages(['message' => $reason]);
                }
                $message = $mark->handle($ticket, $message, $request->user());
            }

            return $message;
        });

        return new TicketConversationResource($result->load('fileAttachments.ticket'));
    }

    private function assert(Ticket $ticket, ?TicketMessage $message = null): void
    {
        app(TicketMutationScopeGuard::class)->assertReadable($ticket);
        if ($message) {
            abort_unless((int) $message->ticket_id === (int) $ticket->id, 404);
        }
    }

    private function assertAttachment(Ticket $ticket, TicketAttachment $attachment): void
    {
        $this->assert($ticket);
        abort_unless((int) $attachment->ticket_id === (int) $ticket->id && $attachment->message
            && (int) $attachment->message->ticket_id === (int) $ticket->id, 404);
    }
}
