<?php

namespace App\Modules\Contact\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Modules\Contact\Models\Contact;
use Illuminate\Support\Facades\DB;

class StoreContact
{
    /**
     * Create a Contact and keep the legacy client_users bridge populated when
     * enough client/site context exists for older ticket and client workflows.
     */
    public function handle(array $data): Contact
    {
        return DB::transaction(function () use ($data): Contact {
            $site = $this->siteFromData($data);
            $client = $this->clientFromData($data, $site);

            $contact = Contact::query()->create([
                'type' => 'person',
                'status' => 'active',
                'display_name' => $data['display_name'],
                'organization_name' => $data['organization_name'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'preferred_language' => $data['preferred_language'] ?? null,
                'communication_language' => $data['preferred_language'] ?? null,
                'metadata' => ['created_from' => 'tech_contacts_create'],
            ]);

            $this->syncEmail($contact, $data['email'] ?? null);
            $this->syncPhone($contact, $data['phone'] ?? null);

            if ($client) {
                $this->syncRelation($contact, $client, $data['relation_type'] ?? 'contact', true);
            }

            if ($site) {
                $this->syncRelation($contact, $site, $data['relation_type'] ?? 'site_contact', true);
                $this->syncClientUserBridge($contact, $site, $data);
            }

            return $contact;
        });
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

    private function syncEmail(Contact $contact, ?string $email): void
    {
        $email = trim((string) $email);

        if ($email === '') {
            return;
        }

        $contact->emails()->create([
            'label' => 'work',
            'email' => $email,
            'is_primary' => true,
        ]);
    }

    private function syncPhone(Contact $contact, ?string $phone): void
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return;
        }

        $contact->phones()->create([
            'label' => 'mobile',
            'phone' => $phone,
            'is_primary' => true,
        ]);
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

    private function syncClientUserBridge(Contact $contact, ClientSite $site, array $data): void
    {
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
