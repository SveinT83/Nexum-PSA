<?php

namespace App\Modules\Contact\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Actions\RepairContactOwnership;
use App\Modules\Contact\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ContactOwnershipController extends Controller
{
    #[OA\Get(
        path: '/api/v1/clients/{client}/contacts',
        operationId: 'getClientTicketContacts',
        summary: 'Get Ticket contact_id for a verified customer. Match the person against legacy_client_users.contact_id, then use the matching active legacy_client_users.id as Ticket contact_id. Never use the global person ID directly.',
        description: 'Requires token contacts.read and current native contact.view. Match contacts.id with active legacy_client_users.contact_id, then use the matching legacy_client_users.id as Ticket contact_id. Verify the intended customer; never use the global person ID as Ticket contact_id. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'client', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactOwnershipInspection')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function index(string $client, RepairContactOwnership $repair): JsonResponse
    {
        return response()->json($repair->inspectClient($repair->resolveClient($client)));
    }

    #[OA\Post(
        path: '/api/v1/contacts/{contact}/move',
        operationId: 'moveContactOwnership',
        summary: 'Preview or move Contact ownership',
        description: 'Requires token contacts.ownership_manage and current native contact.update. Risky writes require preview ETag as If-Match and an Idempotency-Key (8-200 characters). Replays are bound to actor/token/body; stale previews return 412. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'contact', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'If-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContactMoveInput')),
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactRepairResponse')), new OA\Response(response: 202, description: 'Committed state requires delivery reconciliation; do not resend', content: new OA\JsonContent(ref: '#/components/schemas/ContactUnknownResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function move(Request $request, Contact $contact, RepairContactOwnership $repair): JsonResponse
    {
        $validated = $request->validate([
            'target_client_id' => [
                'nullable',
                'integer',
                'required_without:target_client_number',
                Rule::exists('clients', 'id'),
            ],
            'target_client_number' => [
                'nullable',
                'string',
                'max:100',
                'required_without:target_client_id',
            ],
            'target_site_id' => ['nullable', 'integer', Rule::exists('client_sites', 'id')],
            'dry_run' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($request->filled('target_client_id') && $request->filled('target_client_number')) {
            throw ValidationException::withMessages([
                'target_client' => 'Use either target_client_id or target_client_number, not both.',
            ]);
        }

        $targetClient = $repair->resolveTargetClient(
            $validated['target_client_id'] ?? null,
            $validated['target_client_number'] ?? null,
        );

        $payload = $repair->moveContact(
            $contact,
            $targetClient,
            $validated['target_site_id'] ?? null,
            (bool) ($validated['dry_run'] ?? false),
            $validated['reason'] ?? null,
            $request,
        );

        $status = ! $payload['dry_run'] && $payload['plan']['status'] === 'conflict' ? 409 : 200;

        return response()->json($payload, $status);
    }

    #[OA\Post(
        path: '/api/v1/clients/{client}/contacts/bulk-fix',
        operationId: 'bulkFixContactOwnership',
        summary: 'Preview or repair a bounded set of Contact owners',
        description: 'Requires token contacts.ownership_manage and current native contact.update. Risky writes require preview ETag as If-Match and an Idempotency-Key (8-200 characters). Replays are bound to actor/token/body; stale previews return 412. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'client', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'If-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContactBulkInput')),
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactRepairBatchResponse')), new OA\Response(response: 202, description: 'Committed state requires delivery reconciliation; do not resend', content: new OA\JsonContent(ref: '#/components/schemas/ContactUnknownResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function bulkFix(string $client, Request $request, RepairContactOwnership $repair): JsonResponse
    {
        $validated = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1', 'max:500'],
            'contact_ids.*' => ['integer'],
            'target_site_id' => ['nullable', 'integer', Rule::exists('client_sites', 'id')],
            'dry_run' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $targetClient = $repair->resolveClient($client);

        return response()->json($repair->bulkFix(
            $targetClient,
            $validated['contact_ids'],
            $validated['target_site_id'] ?? null,
            (bool) ($validated['dry_run'] ?? true),
            $validated['reason'] ?? null,
            $request,
        ));
    }

    #[OA\Delete(
        path: '/api/v1/clients/{client}/contacts/{contact}',
        operationId: 'detachContactOwnership',
        summary: 'Preview or detach one Contact from a Client',
        description: 'Requires token contacts.ownership_manage and current native contact.update; contact.delete also required for delete_if_orphan. Risky writes require preview ETag as If-Match and an Idempotency-Key (8-200 characters). Replays are bound to actor/token/body; stale previews return 412. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'client', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'contact', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'If-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContactDetachInput')),
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactRepairResponse')), new OA\Response(response: 202, description: 'Committed state requires delivery reconciliation; do not resend', content: new OA\JsonContent(ref: '#/components/schemas/ContactUnknownResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function detach(string $client, Contact $contact, Request $request, RepairContactOwnership $repair): JsonResponse
    {
        $validated = $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
            'delete_if_orphan' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($repair->detachContact(
            $repair->resolveClient($client),
            $contact,
            (bool) ($validated['dry_run'] ?? false),
            (bool) ($validated['delete_if_orphan'] ?? false),
            $validated['reason'] ?? null,
            $request,
        ));
    }

    #[OA\Post(
        path: '/api/v1/clients/{client}/contacts/legacy-orphans/cleanup',
        operationId: 'migrateLegacyContactOrphans',
        summary: 'Preview or migrate orphaned legacy Contacts without deleting bridge IDs',
        description: 'Requires token contacts.ownership_manage and current native contact.create AND contact.update. Risky writes require preview ETag as If-Match and an Idempotency-Key (8-200 characters). Replays are bound to actor/token/body; stale previews return 412. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'client', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'If-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContactCleanupInput')),
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactRepairBatchResponse')), new OA\Response(response: 202, description: 'Committed state requires delivery reconciliation; do not resend', content: new OA\JsonContent(ref: '#/components/schemas/ContactUnknownResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function cleanupLegacyOrphans(string $client, Request $request, RepairContactOwnership $repair): JsonResponse
    {
        $validated = $request->validate([
            'client_user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'client_user_ids.*' => ['integer'],
            'dry_run' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($repair->cleanupLegacyOrphans(
            $repair->resolveClient($client),
            $validated['client_user_ids'],
            (bool) ($validated['dry_run'] ?? true),
            $validated['reason'] ?? null,
            $request,
        ));
    }
}
