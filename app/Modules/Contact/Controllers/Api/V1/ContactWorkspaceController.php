<?php

namespace App\Modules\Contact\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactApiCommand;
use App\Modules\Contact\Queries\ContactApiQuery;
use App\Modules\Contact\Queries\ContactIdentityMatches;
use App\Modules\Contact\Resources\Api\V1\ContactResource;
use App\Modules\Contact\Support\ContactApiAccess;
use App\Modules\Contact\Support\ContactApiInput;
use App\Modules\Contact\Support\ContactSettings;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Signal\Queries\RelatedSignals;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
/** Contact-owned workspace discovery, administration and exact command read-back. */
use OpenApi\Attributes as OA;

class ContactWorkspaceController extends Controller
{
    #[OA\Get(
        path: '/api/v1/contacts/context',
        operationId: 'getContactContext',
        summary: 'Read Contact form defaults, context and capabilities',
        description: 'Requires token contacts.read and current native contact.view OR contact.create OR contact.update. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'client_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'site_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactContext')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function context(Request $request, ContactSettings $settings)
    {
        $data = $request->validate(['client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'site_id' => ['nullable', 'integer', 'exists:client_sites,id'], 'contact_id' => ['nullable', 'integer', 'exists:contacts,id']]);
        [$client, $site] = ContactApiInput::context($data['client_id'] ?? null, $data['site_id'] ?? null);
        $actor = $request->user();
        $editing = ! empty($data['contact_id']);
        if ($editing) {
            ContactApiAccess::authorize($request, ['contact.update'], ['contacts.update']);
        }
        $can = fn ($permission, $ability) => ContactApiAccess::permits($actor, $permission) && $actor->tokenCan($ability);
        $defaults = $settings->get();
        $site ??= $client?->sites()->where('is_default', true)->orderBy('name')->first();
        $contact = $editing ? Contact::with(ContactResource::RELATIONS)->findOrFail($data['contact_id']) : null;

        return response()->json(['data' => [
            'defaults' => $defaults, 'contact_types' => ContactSettings::CONTACT_TYPE_OPTIONS,
            'statuses' => ContactSettings::STATUS_OPTIONS, 'relation_types' => $settings->relationOptions($contact?->relations->first()?->relation_type),
            'client' => $client?->only(['id', 'name']), 'site' => $site?->only(['id', 'client_id', 'name']),
            'contact' => $contact ? (new ContactResource($contact))->resolve($request) : null,
            'portal_roles' => $can('customer_portal.invite', 'contacts.portal_invite') && ! $editing ? CustomerPortalMembership::roleOptions() : (object) [],
            'default_portal_role' => CustomerPortalMembership::ROLE_VIEWER,
            'capabilities' => ['read' => $can('contact.view', 'contacts.read'), 'create' => $can('contact.create', 'contacts.create'),
                'update' => $can('contact.update', 'contacts.update'), 'invite' => ! $editing && $can('customer_portal.invite', 'contacts.portal_invite'),
                'settings' => $can('contact.manage_settings', 'contacts.settings.manage'),
                'invitation_execution_enabled' => (bool) config('contact_api.portal_invitation_execution_enabled')],
        ]]);
    }

    #[OA\Get(
        path: '/api/v1/contacts/lookups/{kind}',
        operationId: 'getContactLookups',
        summary: 'Find bounded Contact form choices',
        description: 'Requires token contacts.read and current native contact.view OR contact.create OR contact.update. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['clients', 'sites', 'titles'])), new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'client_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'site_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))],
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactLookupResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function lookups(Request $request, string $kind)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:255'], 'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'site_id' => ['nullable', 'integer', 'exists:client_sites,id'], 'page' => ['integer', 'min:1'], 'per_page' => ['integer', 'min:1', 'max:100']]);
        ContactApiInput::context($request->integer('client_id') ?: null, $request->integer('site_id') ?: null);
        $q = trim($request->string('q')->toString());
        if ($kind === 'clients') {
            $query = Client::query()->when($q !== '', fn ($query) => $query->where('name', 'like', '%'.$q.'%'))->orderBy('name')->orderBy('id')->select(['id', 'name']);
        } elseif ($kind === 'sites') {
            abort_unless($request->integer('client_id'), 422, 'client_id is required for Site lookup.');
            $query = ClientSite::where('client_id', $request->integer('client_id'))
                ->when($q !== '', fn ($query) => $query->where('name', 'like', '%'.$q.'%'))->orderBy('name')->orderBy('id')->select(['id', 'client_id', 'name', 'is_default']);
        } elseif ($kind === 'titles') {
            abort_unless(ContactApiAccess::permits($request->user(), 'contact.view'), 403);
            $titles = Contact::query()->whereNotNull('job_title')->where('job_title', '!=', '')
                ->when($q !== '', fn ($query) => $query->where('job_title', 'like', '%'.$q.'%'))->distinct()->orderBy('job_title')->limit(20)->pluck('job_title');

            return response()->json(['data' => $titles]);
        } else {
            abort(404);
        }

        return response()->json($query->paginate($request->integer('per_page') ?: 20));
    }

    #[OA\Get(
        path: '/api/v1/contacts/duplicates',
        operationId: 'getContactDuplicateMatches',
        summary: 'Find exact normalized Contact identity matches',
        description: 'Requires token contacts.read and current native contact.view OR contact.create OR contact.update. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'email', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'phone', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'exclude_contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactDuplicates')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function duplicates(Request $request, ContactIdentityMatches $matches)
    {
        $request->validate(['email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:100'],
            'exclude_contact_id' => ['nullable', 'integer', 'exists:contacts,id']]);
        $found = $matches->handle($request->input('email'), $request->input('phone'), $request->integer('exclude_contact_id') ?: null);

        // Form-only actors receive matching identity summaries, never directory/detail authority.
        return response()->json(['data' => $found->take(10)->map(fn ($c) => [
            'id' => $c->id, 'display_name' => $c->display_name, 'organization_name' => $c->organization_name,
            'primary_email' => $c->emails->firstWhere('is_primary', true)?->email ?: $c->emails->first()?->email,
            'primary_phone' => $c->phones->firstWhere('is_primary', true)?->phone ?: $c->phones->first()?->phone,
        ])->values(), 'ambiguous' => $found->count() > 1]);
    }

    #[OA\Get(
        path: '/api/v1/contacts/statistics',
        operationId: 'getContactStatistics',
        summary: 'Read Contact workspace counters',
        description: 'Requires token contacts.read and current native contact.view. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'client_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'site_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactStatistics')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function statistics(Request $request, ContactApiQuery $query)
    {
        $base = $query->handle($request);

        return response()->json(['data' => ['total' => (clone $base)->count(),
            'active' => (clone $base)->where('status', 'active')->count(),
            'people' => (clone $base)->where('type', 'person')->count(),
            'with_email' => (clone $base)->whereHas('emails')->count()]]);
    }

    #[OA\Get(
        path: '/api/v1/contacts/{contact}/signals',
        operationId: 'getContactRelatedSignals',
        summary: 'Read the Contact page related Signal summary',
        description: 'Requires token contacts.read and current native contact.view. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'contact', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactSignals')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function signals(Request $request, Contact $contact, RelatedSignals $signals)
    {
        return response()->json(['data' => $signals->forContact($contact)->map(fn ($signal) => [
            'id' => $signal->id, 'summary' => $signal->summary, 'signal_type' => $signal->signal_type,
            'severity' => $signal->severity, 'source_domain' => $signal->source_domain,
            'confidence' => $signal->confidence, 'occurred_at' => $signal->occurred_at,
            'client_id' => $signal->client_id, 'contact_id' => $signal->contact_id,
        ])->values()]);
    }

    #[OA\Get(
        path: '/api/v1/contacts/settings',
        operationId: 'getContactSettings',
        summary: 'Read Contact settings and choices',
        description: 'Requires token contacts.settings.read and current native contact.manage_settings. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [],
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactSettingsResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function settings(ContactSettings $settings)
    {
        return response()->json(['data' => $settings->get(), 'options' => ['contact_types' => ContactSettings::CONTACT_TYPE_OPTIONS,
            'statuses' => ContactSettings::STATUS_OPTIONS, 'relation_types' => ContactSettings::RELATION_OPTIONS]]);
    }

    #[OA\Put(
        path: '/api/v1/contacts/settings',
        operationId: 'updateContactSettings',
        summary: 'Update Contact defaults and configured relation choices',
        description: 'Requires token contacts.settings.manage and current native contact.manage_settings. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'If-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContactSettingsInput')),
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactSettingsResponse')), new OA\Response(response: 202, description: 'Committed state requires delivery reconciliation; do not resend', content: new OA\JsonContent(ref: '#/components/schemas/ContactUnknownResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function updateSettings(Request $request, ContactSettings $settings)
    {
        $data = $request->validate([
            'default_contact_type' => ['required', Rule::in(array_keys(ContactSettings::CONTACT_TYPE_OPTIONS))],
            'default_status' => ['required', Rule::in(array_keys(ContactSettings::STATUS_OPTIONS))],
            'enabled_relation_types' => ['required', 'array', 'min:1'],
            'enabled_relation_types.*' => [Rule::in(array_keys(ContactSettings::RELATION_OPTIONS))],
            'default_relation_type' => ['required', Rule::in((array) $request->input('enabled_relation_types', []))],
            'send_portal_invitation_by_default' => ['sometimes', 'boolean'],
        ]);
        $data['send_portal_invitation_by_default'] ??= false;
        $saved = $settings->update($data);
        activity('contact')->causedBy($request->user())->event('contact.settings.updated')->withProperties(['settings' => $saved])->log('Contact settings updated through the API.');

        return $this->settings($settings);
    }

    #[OA\Get(
        path: '/api/v1/contacts/operations/{operation}',
        operationId: 'getContactOperation',
        summary: 'Read the original actor/token Contact operation receipt',
        description: 'Requires token contacts.operations.read and current native original current native permissions AND token abilities. ',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Exact operation result', content: new OA\JsonContent(ref: '#/components/schemas/ContactOperation')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 403, description: 'Missing current actor authority or token ability', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 404, description: 'Target not found or receipt belongs to another actor/token', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 409, description: 'Conflict or default-off external execution', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 412, description: 'State changed; preview again', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 422, description: 'Invalid payload or identity', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 428, description: 'Preview ETag and idempotency key required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError')), new OA\Response(response: 503, description: 'Contact API migration required', content: new OA\JsonContent(ref: '#/components/schemas/ContactError'))]
    )]
    public function operation(Request $request, string $operation)
    {
        $command = ContactApiCommand::findOrFail($operation);
        abort_unless((int) $command->actor_id === (int) $request->user()->id
            && hash_equals($command->token_hash, ContactApiAccess::tokenHash($request)), 404);
        ContactApiAccess::authorize($request, $command->result['permissions'], $command->result['abilities']);

        return response()->json(['data' => ['id' => $command->id, 'operation' => $command->operation,
            'state' => $command->state, 'http_status' => $command->result['status'], 'result' => $command->result['body'],
            'created_at' => $command->created_at, 'updated_at' => $command->updated_at]]);
    }
}
