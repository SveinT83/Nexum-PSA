<?php

namespace App\Modules\Contact\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Contact\Actions\StoreContact;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Resources\Api\V1\ContactResource;
use App\Modules\Contact\Support\ContactSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Contacts',
    description: 'API endpoints for contacts and contact automation.'
)]
class ContactController extends Controller
{
    #[OA\Get(
        path: '/api/v1/contacts',
        operationId: 'getContactList',
        description: 'Returns a paginated list of contacts. Supports q, email, phone, and status filters.',
        summary: 'Get list of contacts',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'email', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'email')),
            new OA\Parameter(name: 'phone', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing contacts.read scope'),
        ]
    )]
    public function index(Request $request)
    {
        $query = Contact::query()
            ->with(['emails', 'phones', 'relations'])
            ->orderBy('display_name');

        if ($request->filled('q')) {
            $needle = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($needle): void {
                $inner->where('display_name', 'like', '%'.$needle.'%')
                    ->orWhere('organization_name', 'like', '%'.$needle.'%')
                    ->orWhereHas('emails', fn ($emailQuery) => $emailQuery->where('email', 'like', '%'.$needle.'%'))
                    ->orWhereHas('phones', fn ($phoneQuery) => $phoneQuery->where('phone', 'like', '%'.$needle.'%'));
            });
        }

        if ($request->filled('email')) {
            $email = trim((string) $request->input('email'));
            $query->whereHas('emails', fn ($emailQuery) => $emailQuery->where('email', $email));
        }

        if ($request->filled('phone')) {
            $phone = $this->normalizePhone($request->input('phone'));
            $query->whereHas('phones', function ($phoneQuery) use ($phone): void {
                $phoneQuery->whereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', '') LIKE ?",
                    ['%'.$phone]
                );
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return ContactResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Get(
        path: '/api/v1/contacts/{contact}',
        operationId: 'getContactById',
        description: 'Returns one contact with communication methods and relations.',
        summary: 'Get contact information',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'contact', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing contacts.read scope'),
            new OA\Response(response: 404, description: 'Contact not found'),
        ]
    )]
    public function show(Contact $contact)
    {
        $contact->load(['emails', 'phones', 'addresses', 'relations']);

        return new ContactResource($contact);
    }

    #[OA\Post(
        path: '/api/v1/contacts',
        operationId: 'upsertContact',
        description: 'Creates or updates a contact. Existing contacts are matched by email or normalized phone.',
        summary: 'Create or upsert contact',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['display_name'],
                properties: [
                    new OA\Property(property: 'display_name', type: 'string'),
                    new OA\Property(property: 'organization_name', type: 'string', nullable: true),
                    new OA\Property(property: 'job_title', type: 'string', nullable: true),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'phone', type: 'string', nullable: true),
                    new OA\Property(property: 'sms_allowed', type: 'boolean', nullable: true),
                    new OA\Property(property: 'do_not_email', type: 'boolean', nullable: true),
                    new OA\Property(property: 'marketing_consent', type: 'boolean', nullable: true),
                    new OA\Property(property: 'client_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'site_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'relation_type', type: 'string', nullable: true),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Existing contact updated'),
            new OA\Response(response: 201, description: 'Contact created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing contacts.create or contacts.update scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request, StoreContact $storeContact)
    {
        $validated = $this->validateContactPayload($request, requireName: true);
        $validated['update_existing'] = true;

        $this->ensureSiteBelongsToClient($validated);

        $contact = $storeContact->handle($validated);
        $status = $contact->wasRecentlyCreated ? 201 : 200;

        return (new ContactResource($this->loadContactResourceRelations($contact)))
            ->additional([
                'meta' => [
                    'created' => $contact->wasRecentlyCreated,
                    'upserted' => ! $contact->wasRecentlyCreated,
                ],
            ])
            ->response()
            ->setStatusCode($status);
    }

    #[OA\Put(
        path: '/api/v1/contacts/{contact}',
        operationId: 'replaceContact',
        description: 'Updates a known contact by ID.',
        summary: 'Update contact',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'contact', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contact updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing contacts.update scope'),
            new OA\Response(response: 404, description: 'Contact not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[OA\Patch(
        path: '/api/v1/contacts/{contact}',
        operationId: 'patchContact',
        description: 'Partially updates a known contact by ID.',
        summary: 'Partially update contact',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'contact', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contact updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing contacts.update scope'),
            new OA\Response(response: 404, description: 'Contact not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Contact $contact, StoreContact $storeContact)
    {
        $validated = $this->validateContactPayload($request, requireName: false);
        $validated = array_merge(
            $this->payloadFromExistingContact($contact, $request),
            $validated,
            [
                'existing_contact_id' => $contact->id,
                'update_existing' => true,
            ],
        );

        $this->ensureSiteBelongsToClient($validated);

        $contact = $storeContact->handle($validated);

        return new ContactResource($this->loadContactResourceRelations($contact));
    }

    private function validateContactPayload(Request $request, bool $requireName): array
    {
        $settings = app(ContactSettings::class);
        $requiredNameRule = $requireName ? 'required' : 'sometimes';

        return $request->validate([
            'existing_contact_id' => ['nullable', Rule::exists('contacts', 'id')],
            'display_name' => [$requiredNameRule, 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(array_keys(ContactSettings::CONTACT_TYPE_OPTIONS))],
            'status' => ['sometimes', 'string', Rule::in(array_keys(ContactSettings::STATUS_OPTIONS))],
            'organization_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sms_allowed' => ['sometimes', 'boolean'],
            'preferred_language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'do_not_call' => ['sometimes', 'boolean'],
            'do_not_email' => ['sometimes', 'boolean'],
            'marketing_consent' => ['sometimes', 'boolean'],
            'relation_type' => ['sometimes', 'nullable', Rule::in($settings->enabledRelationValues($request->string('relation_type')->toString()))],
            'client_id' => ['sometimes', 'nullable', Rule::exists('clients', 'id')],
            'site_id' => ['sometimes', 'nullable', Rule::exists('client_sites', 'id')],
        ]);
    }

    private function ensureSiteBelongsToClient(array $data): void
    {
        if (empty($data['client_id']) || empty($data['site_id'])) {
            return;
        }

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

    private function payloadFromExistingContact(Contact $contact, Request $request): array
    {
        $primaryEmail = $contact->emails()->where('is_primary', true)->first() ?: $contact->emails()->first();
        $primaryPhone = $contact->phones()->where('is_primary', true)->first() ?: $contact->phones()->first();
        $clientRelation = $this->currentRelation($contact, new Client());
        $siteRelation = $this->currentRelation($contact, new ClientSite());

        return [
            'display_name' => $contact->display_name,
            'type' => $contact->type,
            'status' => $contact->status,
            'organization_name' => $contact->organization_name,
            'job_title' => $contact->job_title,
            'email' => $primaryEmail?->email,
            'phone' => $primaryPhone?->phone,
            'sms_allowed' => (bool) $primaryPhone?->sms_allowed,
            'preferred_language' => $contact->preferred_language,
            'do_not_call' => $contact->do_not_call,
            'do_not_email' => $contact->do_not_email,
            'marketing_consent' => $contact->marketing_consent,
            'relation_type' => $clientRelation?->relation_type ?? $siteRelation?->relation_type,
            'client_id' => $request->has('client_id') ? null : $clientRelation?->related_id,
            'site_id' => $request->has('site_id') ? null : $siteRelation?->related_id,
        ];
    }

    private function currentRelation(Contact $contact, Client|ClientSite $related)
    {
        return $contact->relations()
            ->where('related_type', $related->getMorphClass())
            ->orderByDesc('is_primary')
            ->latest('id')
            ->first();
    }

    private function loadContactResourceRelations(Contact $contact): Contact
    {
        return $contact->load(['emails', 'phones', 'addresses', 'relations']);
    }

    private function normalizePhone(?string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($normalized, '0047') && strlen($normalized) === 12) {
            return substr($normalized, 4);
        }

        if (str_starts_with($normalized, '47') && strlen($normalized) === 10) {
            return substr($normalized, 2);
        }

        return $normalized;
    }
}
