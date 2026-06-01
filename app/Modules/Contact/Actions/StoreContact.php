<?php

namespace App\Modules\Contact\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Support\ContactSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreContact
{
    /**
     * Create a Contact and keep the legacy client_users bridge populated when
     * enough client/site context exists for older ticket and client workflows.
     */
    public function handle(array $data): Contact
    {
        return DB::transaction(function () use ($data): Contact {
            $settings = app(ContactSettings::class)->get();
            $site = $this->siteFromData($data);
            $client = $this->clientFromData($data, $site);
            $site ??= $client ? $this->defaultSiteForClient($client) : null;
            $contact = $this->contactFromData($data);
            $updateExisting = (bool) ($data['update_existing'] ?? false);

            if ($contact) {
                $this->syncContactFields($contact, $data, $updateExisting);
            } else {
                $contact = Contact::query()->create([
                    'type' => $data['type'] ?? $settings['default_contact_type'],
                    'status' => $data['status'] ?? $settings['default_status'],
                    'display_name' => $data['display_name'],
                    'organization_name' => $data['organization_name'] ?? null,
                    'job_title' => $data['job_title'] ?? null,
                    'preferred_language' => $data['preferred_language'] ?? null,
                    'communication_language' => $data['preferred_language'] ?? null,
                    'metadata' => ['created_from' => 'tech_contacts_create'],
                ]);
            }

            $this->syncEmail($contact, $data['email'] ?? null, $updateExisting);
            $this->syncPhone($contact, $data['phone'] ?? null, $updateExisting);

            if ($updateExisting) {
                $this->replaceClientContext($contact);
            }

            if ($client) {
                $this->syncRelation($contact, $client, $data['relation_type'] ?? $settings['default_relation_type'], true);
            }

            if ($site) {
                $this->syncRelation($contact, $site, $data['relation_type'] ?? $settings['default_relation_type'], true);
                $this->syncClientUserBridge($contact, $site, $data, $updateExisting);
            }

            return $contact;
        });
    }

    private function contactFromData(array $data): ?Contact
    {
        if (! empty($data['existing_contact_id'])) {
            return Contact::query()->find($data['existing_contact_id']);
        }

        $email = trim((string) ($data['email'] ?? ''));

        if ($email !== '') {
            $contact = Contact::query()
                ->whereHas('emails', fn ($query) => $query->where('email', $email))
                ->first();

            if ($contact) {
                return $contact;
            }
        }

        return $this->contactByPhone($data['phone'] ?? null);
    }

    private function contactByPhone(?string $phone): ?Contact
    {
        $normalizedPhone = $this->normalizePhone($phone);

        if ($normalizedPhone === '') {
            return null;
        }

        return Contact::query()
            ->whereHas('phones')
            ->with('phones')
            ->get()
            ->first(fn (Contact $contact) => $contact->phones->contains(
                fn ($contactPhone) => $this->normalizePhone($contactPhone->phone) === $normalizedPhone
            ));
    }

    private function syncContactFields(Contact $contact, array $data, bool $overwrite): void
    {
        $metadata = $contact->metadata ?? [];
        $metadata['updated_from_contact_form'] = true;

        $contact->forceFill([
            'display_name' => $overwrite ? $data['display_name'] : ($contact->display_name ?: $data['display_name']),
            'organization_name' => $overwrite ? ($data['organization_name'] ?? null) : ($contact->organization_name ?: ($data['organization_name'] ?? null)),
            'job_title' => $overwrite ? ($data['job_title'] ?? null) : ($contact->job_title ?: ($data['job_title'] ?? null)),
            'preferred_language' => $overwrite ? ($data['preferred_language'] ?? null) : ($contact->preferred_language ?: ($data['preferred_language'] ?? null)),
            'communication_language' => $overwrite ? ($data['preferred_language'] ?? null) : ($contact->communication_language ?: ($data['preferred_language'] ?? null)),
            'metadata' => $metadata,
        ])->save();
    }

    private function siteFromData(array $data): ?ClientSite
    {
        if (empty($data['site_id'])) {
            return null;
        }

        return ClientSite::query()->with('client')->find($data['site_id']);
    }

    private function clientFromData(array $data, ?ClientSite $site): ?Client
    {
        if ($site?->client) {
            return $site->client;
        }

        if (empty($data['client_id'])) {
            return null;
        }

        return Client::query()->find($data['client_id']);
    }

    private function defaultSiteForClient(Client $client): ?ClientSite
    {
        return $client->sites()
            ->where('is_default', true)
            ->orderBy('name')
            ->first();
    }

    private function syncEmail(Contact $contact, ?string $email, bool $replacePrimary = false): void
    {
        $email = trim((string) $email);

        if ($email === '') {
            return;
        }

        $existingEmail = Contact::query()
            ->whereKeyNot($contact->id)
            ->whereHas('emails', fn ($query) => $query->where('email', $email))
            ->exists();

        if ($existingEmail) {
            throw ValidationException::withMessages([
                'email' => 'This email address already belongs to another contact.',
            ]);
        }

        if ($replacePrimary) {
            $primaryEmail = $contact->emails()->where('is_primary', true)->first() ?: $contact->emails()->first();

            if ($primaryEmail) {
                $primaryEmail->forceFill([
                    'email' => $email,
                    'is_primary' => true,
                ])->save();

                return;
            }
        }

        $contact->emails()->firstOrCreate([
            'email' => $email,
        ], [
            'label' => 'work',
            'is_primary' => ! $contact->emails()->where('is_primary', true)->exists(),
        ]);
    }

    private function syncPhone(Contact $contact, ?string $phone, bool $replacePrimary = false): void
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return;
        }

        $normalizedPhone = $this->normalizePhone($phone);
        $existingPhoneContact = $this->contactByPhone($phone);

        if ($existingPhoneContact && $existingPhoneContact->id !== $contact->id) {
            throw ValidationException::withMessages([
                'phone' => 'This phone number already belongs to another contact.',
            ]);
        }

        $alreadyExists = $contact->phones
            ->contains(fn ($contactPhone) => $this->normalizePhone($contactPhone->phone) === $normalizedPhone);

        if ($alreadyExists) {
            return;
        }

        if ($replacePrimary) {
            $primaryPhone = $contact->phones()->where('is_primary', true)->first() ?: $contact->phones()->first();

            if ($primaryPhone) {
                $primaryPhone->forceFill([
                    'phone' => $phone,
                    'is_primary' => true,
                ])->save();

                return;
            }
        }

        $contact->phones()->create([
            'label' => 'mobile',
            'phone' => $phone,
            'is_primary' => ! $contact->phones()->where('is_primary', true)->exists(),
        ]);
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

    private function syncRelation(Contact $contact, Client|ClientSite $related, string $type, bool $primary): void
    {
        $contact->relations()->firstOrCreate(
            [
                'related_type' => $related->getMorphClass(),
                'related_id' => $related->getKey(),
                'relation_type' => $type,
            ],
            ['is_primary' => $primary]
        );
    }

    private function replaceClientContext(Contact $contact): void
    {
        $clientType = (new Client())->getMorphClass();
        $siteType = (new ClientSite())->getMorphClass();

        $contact->relations()
            ->whereIn('related_type', [$clientType, $siteType])
            ->delete();

        ClientUser::query()
            ->where('contact_id', $contact->id)
            ->delete();
    }

    private function syncClientUserBridge(Contact $contact, ClientSite $site, array $data, bool $replaceExisting = false): void
    {
        if ($replaceExisting) {
            ClientUser::query()
                ->where('contact_id', $contact->id)
                ->where('client_site_id', '!=', $site->id)
                ->delete();
        }

        ClientUser::query()->updateOrCreate(
            ['contact_id' => $contact->id, 'client_site_id' => $site->id],
            [
                'name' => $contact->display_name,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'role' => $data['job_title'] ?? $data['relation_type'] ?? 'Contact',
                'language' => $data['preferred_language'] ?? null,
                'active' => true,
            ]
        );
    }
}
