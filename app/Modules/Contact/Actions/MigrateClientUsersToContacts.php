<?php

namespace App\Modules\Contact\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Modules\Contact\Models\Contact;
use Illuminate\Support\Facades\DB;

class MigrateClientUsersToContacts
{
    public function handle(): array
    {
        $summary = [
            'processed' => 0,
            'created' => 0,
            'linked_existing' => 0,
            'client_relations' => 0,
            'site_relations' => 0,
            'user_links' => 0,
        ];

        ClientUser::query()
            ->with(['site.client', 'user'])
            ->orderBy('id')
            ->chunkById(100, function ($clientUsers) use (&$summary): void {
                foreach ($clientUsers as $clientUser) {
                    DB::transaction(function () use ($clientUser, &$summary): void {
                        $summary['processed']++;

                        [$contact, $created] = $this->contactForClientUser($clientUser);
                        $created ? $summary['created']++ : $summary['linked_existing']++;

                        $clientUser->forceFill(['contact_id' => $contact->id])->save();

                        $this->syncEmail($contact, $clientUser);
                        $this->syncPhone($contact, $clientUser);
                        $this->syncAddress($contact, $clientUser);

                        if ($clientUser->site?->client && $this->syncRelation($contact, $clientUser->site->client, $clientUser->role ?: 'contact', $clientUser->is_default_for_client)) {
                            $summary['client_relations']++;
                        }

                        if ($clientUser->site && $this->syncRelation($contact, $clientUser->site, $clientUser->role ?: 'site_contact', $clientUser->is_default_for_site)) {
                            $summary['site_relations']++;
                        }

                        if ($clientUser->user && ! $clientUser->user->contact_id) {
                            $clientUser->user->forceFill(['contact_id' => $contact->id])->save();
                            $summary['user_links']++;
                        }
                    });
                }
            });

        return $summary;
    }

    private function contactForClientUser(ClientUser $clientUser): array
    {
        $email = trim((string) $clientUser->email);
        $contact = $email !== ''
            ? Contact::query()
                ->whereHas('emails', fn ($query) => $query->where('email', $email))
                ->first()
            : null;

        if ($contact) {
            return [$this->updateContactFromClientUser($contact, $clientUser), false];
        }

        if ($clientUser->contact) {
            return [$this->updateContactFromClientUser($clientUser->contact, $clientUser), false];
        }

        return [
            Contact::query()->create([
                'type' => 'person',
                'status' => $clientUser->active ? 'active' : 'inactive',
                'display_name' => $clientUser->name,
                'job_title' => $clientUser->role,
                'preferred_language' => $clientUser->language,
                'communication_language' => $clientUser->language,
                'metadata' => [
                    'legacy_client_user_id' => $clientUser->id,
                    'migration_source' => 'client_users',
                ],
            ]),
            true,
        ];
    }

    private function updateContactFromClientUser(Contact $contact, ClientUser $clientUser): Contact
    {
        $metadata = $contact->metadata ?? [];
        $metadata['legacy_client_user_ids'] = collect($metadata['legacy_client_user_ids'] ?? [])
            ->push($clientUser->id)
            ->unique()
            ->values()
            ->all();

        $contact->forceFill([
            'status' => $clientUser->active ? 'active' : $contact->status,
            'display_name' => $contact->display_name ?: $clientUser->name,
            'job_title' => $contact->job_title ?: $clientUser->role,
            'preferred_language' => $contact->preferred_language ?: $clientUser->language,
            'communication_language' => $contact->communication_language ?: $clientUser->language,
            'metadata' => $metadata,
        ])->save();

        return $contact;
    }

    private function syncEmail(Contact $contact, ClientUser $clientUser): void
    {
        $email = trim((string) $clientUser->email);

        if ($email === '') {
            return;
        }

        $contact->emails()->firstOrCreate(
            ['email' => $email],
            ['label' => 'work', 'is_primary' => ! $contact->emails()->exists()]
        );
    }

    private function syncPhone(Contact $contact, ClientUser $clientUser): void
    {
        $phone = trim((string) $clientUser->phone);

        if ($phone === '') {
            return;
        }

        $contact->phones()->firstOrCreate(
            ['phone' => $phone],
            ['label' => 'work', 'is_primary' => ! $contact->phones()->exists()]
        );
    }

    private function syncAddress(Contact $contact, ClientUser $clientUser): void
    {
        if (! $clientUser->address && ! $clientUser->zip && ! $clientUser->city) {
            return;
        }

        $contact->addresses()->firstOrCreate(
            [
                'label' => 'office',
                'address' => $clientUser->address,
                'zip' => $clientUser->zip,
                'city' => $clientUser->city,
            ],
            [
                'co_address' => $clientUser->co_address,
                'county' => $clientUser->county,
                'country' => $clientUser->country,
                'is_primary' => ! $contact->addresses()->exists(),
            ]
        );
    }

    private function syncRelation(Contact $contact, Client|ClientSite $related, string $type, bool $primary): bool
    {
        $relation = $contact->relations()
            ->where('related_type', $related->getMorphClass())
            ->where('related_id', $related->getKey())
            ->where('relation_type', $type)
            ->first();

        if ($relation) {
            $relation->forceFill(['is_primary' => $relation->is_primary || $primary])->save();

            return false;
        }

        $contact->relations()->create([
            'related_type' => $related->getMorphClass(),
            'related_id' => $related->getKey(),
            'relation_type' => $type,
            'is_primary' => $primary,
        ]);

        return true;
    }
}
