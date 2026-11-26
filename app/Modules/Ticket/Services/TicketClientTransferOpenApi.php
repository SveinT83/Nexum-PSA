<?php

namespace App\Modules\Ticket\Services;

use OpenApi\Attributes as OA;

/** Staff-only preview and confirmation of a customer transfer; the destination portal starts hidden. */
#[OA\Schema(schema: 'TicketClientTransferResult', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'state', type: 'string', enum: ['preview', 'transferred']),
        new OA\Property(property: 'ticket_id', type: 'integer'), new OA\Property(property: 'ticket_number', type: 'string'),
        new OA\Property(property: 'source_client_id', type: 'integer', nullable: true), new OA\Property(property: 'client_id', type: 'integer'),
        new OA\Property(property: 'site_id', type: 'integer', nullable: true), new OA\Property(property: 'contact_id', type: 'integer', nullable: true),
        new OA\Property(property: 'asset_id', type: 'integer', nullable: true), new OA\Property(property: 'portal_visible', type: 'boolean'),
        new OA\Property(property: 'impact', type: 'object', properties: [new OA\Property(property: 'tasks', type: 'integer'), new OA\Property(property: 'attachments', type: 'integer'), new OA\Property(property: 'time_entries', type: 'integer'), new OA\Property(property: 'cost_entries', type: 'integer'), new OA\Property(property: 'reservations', type: 'integer')]),
        new OA\Property(property: 'revision', type: 'string'), new OA\Property(property: 'replayed', type: 'boolean'),
    ]),
])]
final class TicketClientTransferOpenApi
{
    #[OA\Post(path: '/api/v1/tickets/{ticket}/client-transfer/preview', operationId: 'previewTicketClientTransfer',
        summary: 'Review the destination, retained work and portal visibility', security: [['bearerAuth' => []]], tags: ['Tickets'],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', additionalProperties: false,
            required: ['client_id'], properties: [
                new OA\Property(property: 'client_id', type: 'integer'),
                new OA\Property(property: 'contact_id', type: 'integer', nullable: true),
                new OA\Property(property: 'site_id', type: 'integer', nullable: true),
                new OA\Property(property: 'asset_id', type: 'integer', nullable: true),
            ])),
        responses: [
            new OA\Response(response: 200, description: 'Verified transfer context', content: new OA\JsonContent(ref: '#/components/schemas/TicketClientTransferResult')),
            new OA\Response(response: 401, description: 'Authentication required', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 403, description: 'Missing scope or current authority', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 404, description: 'Ticket not found', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 409, description: 'Stale preview, conflicting retry or unresolved communication', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 422, description: 'Invalid destination context', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
        ], x: ['required-scopes' => ['tickets.update', 'clients.read'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => ['client.view']])]
    public function previewTicketClientTransfer(): void {}

    #[OA\Post(path: '/api/v1/tickets/{ticket}/client-transfer', operationId: 'transferTicketClient',
        summary: 'Apply a reviewed transfer without notifying customers; replay with the same key and revision', security: [['bearerAuth' => []]], tags: ['Tickets'],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', additionalProperties: false,
            required: ['client_id', 'revision', 'idempotency_key'], properties: [
                new OA\Property(property: 'client_id', type: 'integer'),
                new OA\Property(property: 'contact_id', type: 'integer', nullable: true),
                new OA\Property(property: 'site_id', type: 'integer', nullable: true),
                new OA\Property(property: 'asset_id', type: 'integer', nullable: true),
                new OA\Property(property: 'revision', type: 'string'),
                new OA\Property(property: 'idempotency_key', type: 'string'),
            ])),
        responses: [
            new OA\Response(response: 200, description: 'Verified transfer context', content: new OA\JsonContent(ref: '#/components/schemas/TicketClientTransferResult')),
            new OA\Response(response: 401, description: 'Authentication required', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 403, description: 'Missing scope or current authority', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 404, description: 'Ticket not found', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 409, description: 'Stale preview, conflicting retry or unresolved communication', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 422, description: 'Invalid destination context', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
        ], x: ['required-scopes' => ['tickets.update', 'clients.read'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => ['client.view']])]
    public function transferTicketClient(): void {}
}
