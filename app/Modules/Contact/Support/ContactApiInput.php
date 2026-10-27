<?php

namespace App\Modules\Contact\Support;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Contact\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Validate only workflow fields; omitted PATCH fields never become destructive defaults. */
class ContactApiInput
{
    public static function payload(Request $request, ?Contact $contact = null): array
    {
        $settings = app(ContactSettings::class);
        $oldRelation = $contact?->relations()->orderByDesc('is_primary')->orderBy('id')->value('relation_type');
        $data = $request->validate([
            'existing_contact_id' => ['sometimes', 'nullable', 'integer', Rule::exists('contacts', 'id')->whereNull('deleted_at')],
            'display_name' => [$contact ? 'sometimes' : 'required', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(array_keys(ContactSettings::CONTACT_TYPE_OPTIONS))],
            'status' => ['sometimes', Rule::in(array_keys(ContactSettings::STATUS_OPTIONS))],
            'organization_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sms_allowed' => ['sometimes', 'boolean'],
            'preferred_language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'do_not_call' => ['sometimes', 'boolean'], 'do_not_email' => ['sometimes', 'boolean'],
            'marketing_consent' => ['sometimes', 'boolean'],
            'relation_type' => ['sometimes', 'nullable', Rule::in($settings->enabledRelationValues($oldRelation))],
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')],
            'site_id' => ['sometimes', 'nullable', 'integer', Rule::exists('client_sites', 'id')],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'co_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'zip' => ['sometimes', 'nullable', 'string', 'max:20'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'county' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reuse_existing' => ['sometimes', 'boolean'],
            'send_customer_portal_invitation' => ['sometimes', 'boolean'],
            'portal_role' => ['sometimes', Rule::in(array_keys(\App\Modules\CustomerPortal\Models\CustomerPortalMembership::roleOptions()))],
        ]);
        if ($contact) {
            $email = $contact->emails()->orderByDesc('is_primary')->orderBy('id')->first();
            $phone = $contact->phones()->orderByDesc('is_primary')->orderBy('id')->first();
            $address = $contact->addresses()->orderByDesc('is_primary')->orderBy('id')->first();
            $client = $contact->relations()->where('related_type', (new Client)->getMorphClass())->orderByDesc('is_primary')->orderBy('id')->first();
            $site = $contact->relations()->where('related_type', (new ClientSite)->getMorphClass())->orderByDesc('is_primary')->orderBy('id')->first();
            $base = $contact->only(['display_name', 'type', 'status', 'organization_name', 'job_title', 'preferred_language', 'do_not_call', 'do_not_email', 'marketing_consent']);
            $base += ['email' => $email?->email, 'phone' => $phone?->phone, 'sms_allowed' => (bool) $phone?->sms_allowed,
                'client_id' => $client?->related_id, 'site_id' => $site?->related_id, 'relation_type' => $oldRelation];
            foreach (['address', 'co_address', 'zip', 'city', 'county', 'country'] as $field) {
                $base[$field] = $address?->{$field};
            }
            if ($request->has('client_id') && ! $request->has('site_id') && (int) $data['client_id'] !== (int) $base['client_id']) {
                $base['site_id'] = null;
            }
            $data = array_merge($base, $data, ['existing_contact_id' => $contact->id, 'update_existing' => true,
                '_preserve_context' => ! $request->hasAny(['client_id', 'site_id', 'relation_type'])]);
        } else {
            $data['update_existing'] = ! ($data['reuse_existing'] ?? false);
            $data['_reuse_unchanged'] = (bool) ($data['reuse_existing'] ?? false);
        }
        self::context($data['client_id'] ?? null, $data['site_id'] ?? null);

        return $data;
    }

    public static function context(?int $clientId, ?int $siteId): array
    {
        $site = $siteId ? ClientSite::findOrFail($siteId) : null;
        $client = $clientId ? Client::findOrFail($clientId) : $site?->client;
        if ($site && $client && (int) $site->client_id !== (int) $client->id) {
            throw ValidationException::withMessages(['site_id' => 'The selected site does not belong to the selected client.']);
        }

        return [$client, $site];
    }
}
