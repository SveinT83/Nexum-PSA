<?php

namespace App\Modules\Contact\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Actions\StoreContact;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Resources\Api\V1\ContactResource;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ContactController extends Controller
{
    #[OA\Get(
        path: '/api/v1/contacts',
        operationId: 'getContactList',
        summary: 'List and search canonical Contacts',
        description: 'Requires token contacts.read and current native contact.view. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'email', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'phone', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive', 'archived'])), new OA\Parameter(name: 'client_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'site_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))],
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactPage')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function index(Request $request)
    {
        $query = app(\App\Modules\Contact\Queries\ContactApiQuery::class)->handle($request);

        return ContactResource::collection($query->paginate($request->integer('per_page') ?: 25));
    }

    #[OA\Get(
        path: '/api/v1/contacts/{contact}',
        operationId: 'getContactById',
        summary: 'Read Contact details and compatibility IDs',
        description: 'Requires token contacts.read and current native contact.view OR contact.update. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'contact', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function show(Contact $contact)
    {
        $contact->load(ContactResource::RELATIONS);

        return new ContactResource($contact);
    }

    #[OA\Post(
        path: '/api/v1/contacts',
        operationId: 'upsertContact',
        summary: 'Create, reuse or upsert a canonical Contact',
        description: 'Requires token contacts.create AND contacts.update and current native contact.create AND contact.update. Explicit invitation additionally requires contacts.portal_invite and customer_portal.invite; API never inherits the UI invitation default. Viewer is the role default. Preview rolls back; preview IDs are not saved records. Risky writes require preview ETag as If-Match and an Idempotency-Key (8-200 characters). Replays are bound to actor/token/body; stale previews return 412. Both create and update authority are required because POST is an upsert. reuse_existing preserves existing person fields. Invitation execution is default-off. Queued is not inbox delivery. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'If-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContactCreateInput')),
        responses: [new OA\Response(response: 201, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactResponse')), new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactResponse')), new OA\Response(response: 202, description: 'Committed state requires delivery reconciliation; do not resend', content: new OA\JsonContent(ref: '#/components/schemas/ContactUnknownResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function store(Request $request, StoreContact $storeContact)
    {
        $data = \App\Modules\Contact\Support\ContactApiInput::payload($request);
        $invitation = null;
        $contact = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $data, $storeContact, &$invitation) {
            $contact = $storeContact->handle($data);
            if ($request->boolean('send_customer_portal_invitation')) {
                [$client, $site] = \App\Modules\Contact\Support\ContactApiInput::context($data['client_id'] ?? null, $data['site_id'] ?? null);
                if (! $client) {
                    throw ValidationException::withMessages(['client_id' => 'A Client is required for a portal invitation.']);
                }
                $invitation = app(\App\Modules\CustomerPortal\Actions\CreateCustomerPortalInvitation::class)->handle(
                    $request->user(), $contact, $client, $site,
                    $data['portal_role'] ?? \App\Modules\CustomerPortal\Models\CustomerPortalMembership::ROLE_VIEWER,
                    $data['email'] ?? null, 'contact_api_create');
            }

            return $contact;
        });
        $created = $contact->wasRecentlyCreated;

        return (new ContactResource($contact->load(\App\Modules\Contact\Resources\Api\V1\ContactResource::RELATIONS)))
            ->additional(['meta' => ['created' => $created, 'upserted' => ! $created, 'reused' => $request->boolean('reuse_existing') && ! $created],
                'portal_invitation' => $invitation ? ['id' => $invitation->id, 'contact_id' => $contact->id,
                    'client_id' => $invitation->client_id, 'site_id' => $invitation->site_id, 'role' => $invitation->role,
                    'state' => 'queued', 'inbox_delivery_verified' => false] : null])
            ->response()->setStatusCode($created ? 201 : 200);
    }

    /** Validate and simulate the same action in a rolled-back transaction; no queued invitation. */
    #[OA\Post(
        path: '/api/v1/contacts/preview',
        operationId: 'previewContactCreate',
        summary: 'Preview Contact creation and an optional portal invitation',
        description: 'Requires token contacts.create AND contacts.update and current native contact.create AND contact.update. Explicit invitation additionally requires contacts.portal_invite and customer_portal.invite; API never inherits the UI invitation default. Viewer is the role default. Preview rolls back; preview IDs are not saved records. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContactCreateInput')),
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactPreview')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function preview(Request $request, StoreContact $storeContact)
    {
        $data = \App\Modules\Contact\Support\ContactApiInput::payload($request);
        $connection = \Illuminate\Support\Facades\DB::connection();
        $connection->beginTransaction();
        try {
            $contact = $storeContact->handle($data);
            $result = (new ContactResource($contact->load(ContactResource::RELATIONS)))->resolve($request);
            if ($request->boolean('send_customer_portal_invitation')) {
                [$client, $site] = \App\Modules\Contact\Support\ContactApiInput::context($data['client_id'] ?? null, $data['site_id'] ?? null);
                if (! $client) {
                    throw ValidationException::withMessages(['client_id' => 'A Client is required for a portal invitation.']);
                }
                app(\App\Modules\CustomerPortal\Actions\CreateCustomerPortalInvitation::class)->validateInvitation(
                    $contact, $client, $site, $data['portal_role'] ?? \App\Modules\CustomerPortal\Models\CustomerPortalMembership::ROLE_VIEWER,
                    $data['email'] ?? null);
            }
        } finally {
            $connection->rollBack();
        }

        return response()->json(['dry_run' => true, 'data' => $result, 'invitation_requested' => $request->boolean('send_customer_portal_invitation'),
            'execution_enabled' => (bool) config('contact_api.portal_invitation_execution_enabled')]);
    }

    #[OA\Put(
        path: '/api/v1/contacts/{contact}',
        operationId: 'replaceContact',
        summary: 'Update a known Contact',
        description: 'Requires token contacts.update and current native contact.update. Omitted fields/relations are preserved. Null email/phone retain the existing communication endpoint, matching the browser; nullable address/profile fields can be cleared. Invitation fields are prohibited. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'contact', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'If-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContactUpdateInput')),
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactResponse')), new OA\Response(response: 202, description: 'Committed state requires delivery reconciliation; do not resend', content: new OA\JsonContent(ref: '#/components/schemas/ContactUnknownResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    #[OA\Patch(
        path: '/api/v1/contacts/{contact}',
        operationId: 'patchContact',
        summary: 'Partially update a known Contact',
        description: 'Requires token contacts.update and current native contact.update. Omitted fields/relations are preserved. Null email/phone retain the existing communication endpoint, matching the browser; nullable address/profile fields can be cleared. Invitation fields are prohibited. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'contact', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'If-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContactUpdateInput')),
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactResponse')), new OA\Response(response: 202, description: 'Committed state requires delivery reconciliation; do not resend', content: new OA\JsonContent(ref: '#/components/schemas/ContactUnknownResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function update(Request $request, Contact $contact, StoreContact $storeContact)
    {
        $data = \App\Modules\Contact\Support\ContactApiInput::payload($request, $contact);
        $contact = $storeContact->handle($data);

        return new ContactResource($contact->load(ContactResource::RELATIONS));
    }
}
