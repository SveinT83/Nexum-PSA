<?php

namespace App\Modules\Contact\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Modules\Contact\Models\Contact;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
            ->orderBy('id')
            ->chunkById(100, function ($clientUsers) use (&$summary): void {
                foreach ($clientUsers as $clientUser) {
                    $result = $this->migrateClientUser($clientUser);

                    foreach (array_keys($summary) as $key) {
                        $summary[$key] += (int) ($result[$key] ?? 0);
                    }
                }
            });

        return $summary;
    }

    public function migrateOne(ClientUser $clientUser): Contact
    {
        return $this->migrateClientUser($clientUser)['contact'];
    }

    /**
     * @return array{contact: Contact, processed: int, created: int, linked_existing: int, client_relations: int, site_relations: int, user_links: int}
     */
    private function migrateClientUser(ClientUser $clientUser): array
    {
        return DB::transaction(function () use ($clientUser): array {
            $locked = ClientUser::query()
                ->whereKey($clientUser->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->load(['site.client', 'user.contact', 'contact']);

            [$contact, $created] = $this->contactForClientUser($locked);
            $locked->forceFill(['contact_id' => $contact->id])->save();

            $this->syncEmail($contact, $locked);
            $this->syncPhone($contact, $locked);
            $this->syncAddress($contact, $locked);

            $clientRelationCreated = false;
            $siteRelationCreated = false;

            if ($locked->site?->client) {
                $clientRelationCreated = $this->syncRelation(
                    $contact,
                    $locked->site->client,
                    $locked->role ?: 'contact',
                    $locked->is_default_for_client,
                );
            }

            if ($locked->site) {
                $siteRelationCreated = $this->syncRelation(
                    $contact,
                    $locked->site,
                    $locked->role ?: 'site_contact',
                    $locked->is_default_for_site,
                );
            }

            $userLinked = false;

            if ($locked->user) {
                if ($locked->user->contact_id && (int) $locked->user->contact_id !== (int) $contact->id) {
                    throw new RuntimeException('Canonical Contact cutover stopped because a linked user has conflicting identity evidence.');
                }

                if (! $locked->user->contact_id) {
                    $locked->user->forceFill(['contact_id' => $contact->id])->save();
                    $userLinked = true;
                }
            }

            return [
                'contact' => $contact,
                'processed' => 1,
                'created' => $created ? 1 : 0,
                'linked_existing' => $created ? 0 : 1,
                'client_relations' => $clientRelationCreated ? 1 : 0,
                'site_relations' => $siteRelationCreated ? 1 : 0,
                'user_links' => $userLinked ? 1 : 0,
            ];
        }, 3);
    }

    /**
     * Explicit compatibility and user links are authoritative. Identity
     * matching is allowed only when email/phone evidence resolves to one row.
     *
     * @return array{0: Contact, 1: bool}
     */
    private function contactForClientUser(ClientUser $clientUser): array
    {
        $explicit = null;

        if ($clientUser->contact_id) {
            $explicit = Contact::query()->find($clientUser->contact_id);

            if (! $explicit) {
                throw new RuntimeException('Canonical Contact cutover stopped because an explicit Contact link is unavailable.');
            }
        }

        $userContact = null;

        if ($clientUser->user?->contact_id) {
            $userContact = Contact::query()->find($clientUser->user->contact_id);

            if (! $userContact) {
                throw new RuntimeException('Canonical Contact cutover stopped because a user Contact link is unavailable.');
            }
        }

        if ($explicit && $userContact && (int) $explicit->id !== (int) $userContact->id) {
            throw new RuntimeException('Canonical Contact cutover stopped because explicit identity links conflict.');
        }

        if ($explicit || $userContact) {
            return [
                $this->updateContactFromClientUser($explicit ?: $userContact, $clientUser),
                false,
            ];
        }

        $matches = collect();
        $email = mb_strtolower(trim((string) $clientUser->email));

        if ($email !== '') {
            $emailMatches = Contact::query()
                ->whereHas('emails', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))
                ->get();

            if ($emailMatches->count() > 1) {
                throw new RuntimeException('Canonical Contact cutover stopped because email identity evidence is ambiguous.');
            }

            $matches = $matches->merge($emailMatches);
        }

        $phone = $this->normalizePhone($clientUser->phone);

        if ($phone !== '') {
            $phoneMatches = Contact::query()
                ->whereHas('phones')
                ->with('phones')
                ->get()
                ->filter(fn (Contact $contact): bool => $contact->phones->contains(
                    fn ($contactPhone): bool => $this->normalizePhone($contactPhone->phone) === $phone
                ))
                ->values();

            if ($phoneMatches->count() > 1) {
                throw new RuntimeException('Canonical Contact cutover stopped because phone identity evidence is ambiguous.');
            }

            $matches = $matches->merge($phoneMatches);
        }

        $matches = $matches->unique('id')->values();

        if ($matches->count() > 1) {
            throw new RuntimeException('Canonical Contact cutover stopped because email and phone identity evidence disagree.');
        }

        if ($matches->isNotEmpty()) {
            return [
                $this->updateContactFromClientUser($matches->first(), $clientUser),
                false,
            ];
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
                    'legacy_client_user_ids' => [$clientUser->id],
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
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $metadata['migration_source'] = $metadata['migration_source'] ?? 'client_users';

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

        $exists = $contact->emails()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->exists();

        if (! $exists) {
            $contact->emails()->create([
                'email' => $email,
                'label' => 'work',
                'is_primary' => ! $contact->emails()->where('is_primary', true)->exists(),
            ]);
        }
    }

    private function syncPhone(Contact $contact, ClientUser $clientUser): void
    {
        $phone = trim((string) $clientUser->phone);
        $normalized = $this->normalizePhone($phone);

        if ($normalized === '') {
            return;
        }

        $exists = $contact->phones()
            ->get()
            ->contains(fn ($contactPhone): bool => $this->normalizePhone($contactPhone->phone) === $normalized);

        if (! $exists) {
            $contact->phones()->create([
                'phone' => $phone,
                'label' => 'work',
                'is_primary' => ! $contact->phones()->where('is_primary', true)->exists(),
            ]);
        }
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
                'is_primary' => ! $contact->addresses()->where('is_primary', true)->exists(),
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
            if ($primary && ! $relation->is_primary) {
                $relation->forceFill(['is_primary' => true])->save();
            }

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
