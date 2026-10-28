<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Resources\Api\V1\DomainApiAuditResource;
use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Ticket\Queries\TicketSettingsReferenceQuery;
use App\Modules\Ticket\Services\TicketSettingsEditor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** Explicit settings policies retain their browser domain permission and never accept arbitrary keys. */
class TicketSettingsApiController extends Controller
{
    #[OA\Get(path: '/api/v1/ticket-settings', operationId: 'ticketTicketSettingsApiControllershow', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.settings.read'], 'domain-permissions' => 'ticket.manage_settings', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketSettingsReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(TicketSettingsEditor $editor)
    {
        return response()->json(['data' => $editor->settings()]);
    }

    #[OA\Put(path: '/api/v1/ticket-settings/default-email-account', operationId: 'ticketTicketSettingsApiControlleremail', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.settings.update'], 'domain-permissions' => 'ticket.manage_settings', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'email_account_id', type: 'integer', nullable: true)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketSettingsReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function email(Request $request, TicketSettingsEditor $editor)
    {
        $editor->updateEmail($request);

        return $this->show($editor);
    }

    #[OA\Put(path: '/api/v1/ticket-settings/solution-policy', operationId: 'ticketTicketSettingsApiControllersolution', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.settings.update'], 'domain-permissions' => 'ticket.manage_settings', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'allow_internal_solution_notes', type: 'boolean')])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketSettingsReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function solution(Request $request, TicketSettingsEditor $editor)
    {
        $editor->updateSolution($request);

        return $this->show($editor);
    }

    #[OA\Put(path: '/api/v1/ticket-settings/portal-policy', operationId: 'ticketTicketSettingsApiControllerportal', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.settings.update'], 'domain-permissions' => 'ticket.manage_settings', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['default_customer_visibility'], properties: [new OA\Property(property: 'default_customer_visibility', type: 'string', enum: ['published', 'unpublished'])])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketSettingsReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function portal(Request $request, TicketSettingsEditor $editor)
    {
        $editor->updatePortal($request);

        return $this->show($editor);
    }

    #[OA\Put(path: '/api/v1/ticket-settings/quote-cost-policy', operationId: 'ticketTicketSettingsApiControllerquote', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.settings.update'], 'domain-permissions' => 'ticket.manage_settings', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'quote_required_cost_threshold', type: 'number', minimum: 0, maximum: 9999999999.99, nullable: true)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketSettingsReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function quote(Request $request, TicketSettingsEditor $editor)
    {
        $editor->updateQuote($request);

        return $this->show($editor);
    }

    #[OA\Put(path: '/api/v1/ticket-settings/merge-policy', operationId: 'ticketTicketSettingsApiControllermerge', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [], x: ['required-scopes' => ['tickets.settings.update'], 'domain-permissions' => 'ticket.manage_settings', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['ai_similarity_threshold'], properties: [new OA\Property(property: 'auto_merge_enabled', type: 'boolean'), new OA\Property(property: 'ai_merge_enabled', type: 'boolean'), new OA\Property(property: 'ai_similarity_threshold', type: 'integer', minimum: 70, maximum: 100)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketSettingsReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function merge(Request $request, TicketSettingsEditor $editor)
    {
        $editor->updateMerge($request);

        return $this->show($editor);
    }

    #[OA\Get(path: '/api/v1/ticket-settings/context/{catalog}', operationId: 'ticketTicketSettingsApiControllercontext', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'catalog', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)), new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.settings.read'], 'domain-permissions' => 'ticket.manage_settings', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ParityReferenceOption')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function context(Request $request, string $catalog, TicketSettingsReferenceQuery $query)
    {
        return JsonResource::collection($query->paginate($request, $catalog));
    }

    #[OA\Get(path: '/api/v1/ticket-settings/history', operationId: 'ticketTicketSettingsApiControllerhistory', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tickets.settings.read'], 'domain-permissions' => 'ticket.manage_settings', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DomainApiAuditReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function history(Request $request, DomainApiAudit $audit)
    {
        $query = $audit->query('ticket')->where(fn ($q) => $q->where('event', 'like', 'ticket.settings.%')
            ->orWhere('event', 'like', 'ticket.catalog.%')->orWhere('event', 'like', 'ticket.assignment.%'));

        return DomainApiAuditResource::collection($query->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }
}
