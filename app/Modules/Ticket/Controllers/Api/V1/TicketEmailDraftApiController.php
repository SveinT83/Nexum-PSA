<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Ticket\Actions\PrepareTicketEmailDraftViaApi;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEmailOutboundCommunication;
use App\Modules\Ticket\Resources\Api\V1\TicketEmailCommunicationApiResource;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** Selected Mail conversations stay bound to their exact Ticket, source placement and native Mail draft/send authority. */
class TicketEmailDraftApiController extends Controller
{
    #[OA\Get(path: '/api/v1/tickets/{ticket}/email-relationships', operationId: 'ticketTicketEmailDraftApiControllerrelationships', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.email-drafts.read', 'email.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketEmailRelationshipReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function relationships(Request $request, Ticket $ticket, MailboxAccess $access)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $accounts = $access->scopeAccounts(EmailAccount::query(), $request->user(), MailboxAccess::VIEW)->select('email_accounts.id');
        $links = EmailTicketConversationLink::query()->where('ticket_id', $ticket->id)->whereIn('account_id', $accounts)->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->orderBy('id')->paginate(DomainApiRead::pageSize($request))->withQueryString();
        $links->through(fn ($link) => ['id' => $link->id, 'ticket_id' => $link->ticket_id, 'account_id' => $link->account_id, 'email_conversation_id' => $link->email_conversation_id,
            'source_message_id' => $link->email_message_id, 'source_placement_id' => $link->email_mailbox_placement_id, 'relationship_role' => $link->relationship_role, 'audience' => $link->audience, 'status' => $link->status]);

        return JsonResource::collection($links);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/email-relationships/{relationship}/reply-draft', operationId: 'ticketTicketEmailDraftApiControllerprepare', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'relationship', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.email-drafts.create', 'email.drafts.write'], 'domain-permissions' => 'ticket.reply_customer', 'additional-domain-permissions' => ['ticket.view']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TicketEmailDraftPayload')), responses: [new OA\Response(response: 409, description: 'Conflicting retry key or stale draft checksum', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketEmailCommunicationReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function prepare(Request $request, Ticket $ticket, int $relationship, PrepareTicketEmailDraftViaApi $prepare)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['mode' => 'required|in:reply,reply_all', 'idempotency_key' => 'required|string|max:191']);

        return new TicketEmailCommunicationApiResource($prepare->handle($ticket, $relationship, $request->user(), $data)->load(['draft', 'ticket']));
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/email-communications/{communication}', operationId: 'ticketTicketEmailDraftApiControllershow', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'communication', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.email-drafts.read', 'email.drafts.read'], 'domain-permissions' => 'ticket.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketEmailCommunicationReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(Request $request, Ticket $ticket, int $communication, MailboxAccess $access)
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $accounts = $access->scopeAccounts(EmailAccount::query(), $request->user(), MailboxAccess::VIEW)->select('email_accounts.id');
        $result = TicketEmailOutboundCommunication::query()->with(['draft', 'ticket'])->where('ticket_id', $ticket->id)->whereIn('email_account_id', $accounts)
            ->where(fn ($q) => $q->where('actor_id', $request->user()->id)->orWhereHas('draft', fn ($draft) => $draft->where('scope', EmailComposerDraft::SCOPE_SHARED)))->findOrFail($communication);

        return new TicketEmailCommunicationApiResource($result);
    }
}
