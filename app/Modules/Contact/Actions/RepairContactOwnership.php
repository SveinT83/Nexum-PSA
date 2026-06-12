<?php

namespace App\Modules\Contact\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactRelation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepairContactOwnership
{
    public function resolveClient(string|int $identifier): Client
    {
        $value = trim((string) $identifier);

        $query = Client::query()->with('sites')->where('client_number', $value);

        if (ctype_digit($value)) {
            $query->orWhere('id', (int) $value);
        }

        $matches = $query->get()->unique('id')->values();

        if ($matches->isEmpty()) {
            throw (new ModelNotFoundException())->setModel(Client::class, [$value]);
        }

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'client' => 'The client identifier matches more than one client. Use the internal client ID or a unique client_number.',
            ]);
        }

        return $matches->first();
    }

    public function resolveTargetClient(?int $targetClientId, ?string $targetClientNumber): Client
    {
        if ($targetClientId) {
            return Client::query()->with('sites')->findOrFail($targetClientId);
        }

        $client = Client::query()
            ->with('sites')
            ->where('client_number', trim((string) $targetClientNumber))
            ->first();

        if (! $client) {
            throw (new ModelNotFoundException())->setModel(Client::class, [$targetClientNumber]);
        }

        return $client;
    }

    public function inspectClient(Client $client): array
    {
        $client->loadMissing('sites');

        $siteIds = $client->sites->pluck('id')->all();
        $clientMorph = (new Client())->getMorphClass();
        $siteMorph = (new ClientSite())->getMorphClass();

        $relationContactIds = ContactRelation::query()
            ->where(function ($query) use ($client, $clientMorph, $siteMorph, $siteIds): void {
                $query->where(function ($inner) use ($client, $clientMorph): void {
                    $inner->where('related_type', $clientMorph)
                        ->where('related_id', $client->id);
                });

                if ($siteIds !== []) {
                    $query->orWhere(function ($inner) use ($siteMorph, $siteIds): void {
                        $inner->where('related_type', $siteMorph)
                            ->whereIn('related_id', $siteIds);
                    });
                }
            })
            ->pluck('contact_id');

        $legacyClientUsers = ClientUser::query()
            ->with(['site.client', 'contact.emails', 'contact.phones'])
            ->whereIn('client_site_id', $siteIds)
            ->orderBy('name')
            ->get();

        $contactIds = $relationContactIds
            ->merge($legacyClientUsers->pluck('contact_id')->filter())
            ->unique()
            ->values();

        $contacts = Contact::query()
            ->with(['emails', 'phones', 'relations'])
            ->whereIn('id', $contactIds)
            ->orderBy('display_name')
            ->get();

        return [
            'client' => $this->clientPayload($client),
            'summary' => [
                'contacts' => $contacts->count(),
                'legacy_client_users' => $legacyClientUsers->count(),
                'legacy_without_contact' => $legacyClientUsers->whereNull('contact_id')->count(),
            ],
            'contacts' => $contacts
                ->map(fn (Contact $contact) => $this->contactOwnershipPayload($contact))
                ->values()
                ->all(),
            'legacy_client_users' => $legacyClientUsers
                ->map(fn (ClientUser $clientUser) => $this->legacyClientUserPayload($clientUser))
                ->values()
                ->all(),
        ];
    }

    public function moveContact(
        Contact $contact,
        Client $targetClient,
        ?int $targetSiteId,
        bool $dryRun,
        ?string $reason,
        Request $request
    ): array {
        $targetSite = $this->targetSiteForClient($targetClient, $targetSiteId);
        $before = $this->ownershipSnapshot($contact);
        $plan = $this->buildMovePlan($contact, $targetClient, $targetSite, bulkMode: false);

        if ($dryRun || $plan['status'] === 'conflict') {
            $auditId = $this->recordAudit($request, 'contact_ownership.move', $contact, null, $targetClient, $dryRun, $reason, $before, $plan);

            return [
                'dry_run' => $dryRun,
                'changed' => false,
                'audit_id' => $auditId,
                'plan' => $plan,
                'contact' => $this->contactOwnershipPayload($contact->fresh(['emails', 'phones', 'relations'])),
            ];
        }

        DB::transaction(function () use ($contact, $targetClient, $targetSite, $plan): void {
            ContactRelation::query()
                ->whereIn('id', $plan['delete_relation_ids'])
                ->delete();

            $relationType = $plan['relation_type'];

            $contact->relations()->firstOrCreate(
                [
                    'related_type' => $targetClient->getMorphClass(),
                    'related_id' => $targetClient->id,
                    'relation_type' => $relationType,
                ],
                ['is_primary' => true]
            );

            if ($targetSite) {
                $contact->relations()->firstOrCreate(
                    [
                        'related_type' => $targetSite->getMorphClass(),
                        'related_id' => $targetSite->id,
                        'relation_type' => $relationType,
                    ],
                    ['is_primary' => true]
                );

                $this->syncSingleLegacyBridge($contact, $targetSite);
            }
        });

        $freshContact = $contact->fresh(['emails', 'phones', 'relations']);
        $after = $this->ownershipSnapshot($freshContact);
        $result = $this->buildMovePlan($freshContact, $targetClient, $targetSite, bulkMode: false);
        $auditId = $this->recordAudit($request, 'contact_ownership.move', $freshContact, null, $targetClient, $dryRun, $reason, $before, $result, $after);

        return [
            'dry_run' => false,
            'changed' => true,
            'audit_id' => $auditId,
            'plan' => $result,
            'contact' => $this->contactOwnershipPayload($freshContact),
        ];
    }

    public function bulkFix(
        Client $targetClient,
        array $contactIds,
        ?int $targetSiteId,
        bool $dryRun,
        ?string $reason,
        Request $request
    ): array {
        $targetSite = $this->targetSiteForClient($targetClient, $targetSiteId);
        $results = [];
        $changed = 0;

        foreach (array_values(array_unique($contactIds)) as $contactId) {
            $contact = Contact::query()->with(['emails', 'phones', 'relations'])->find($contactId);

            if (! $contact) {
                $results[] = [
                    'contact_id' => (int) $contactId,
                    'status' => 'missing_contact',
                    'message' => 'Contact was not found.',
                ];

                continue;
            }

            $plan = $this->buildMovePlan($contact, $targetClient, $targetSite, bulkMode: true);

            if (! $dryRun && $plan['status'] !== 'conflict' && $plan['status'] !== 'no_change') {
                $this->moveContact($contact, $targetClient, $targetSite?->id, false, $reason, $request);
                $changed++;
                $plan = $this->buildMovePlan($contact->fresh(['emails', 'phones', 'relations']), $targetClient, $targetSite, bulkMode: true);
            }

            $results[] = [
                'contact_id' => $contact->id,
                'display_name' => $contact->display_name,
                'status' => $plan['status'],
                'conflicts' => $plan['conflicts'],
                'changes' => $plan['changes'],
            ];
        }

        $summary = [
            'total' => count(array_values(array_unique($contactIds))),
            'changed' => $changed,
            'dry_run' => $dryRun,
            'conflicts' => collect($results)->where('status', 'conflict')->count(),
            'missing_contacts' => collect($results)->where('status', 'missing_contact')->count(),
            'no_change' => collect($results)->where('status', 'no_change')->count(),
        ];

        $auditId = $this->recordAudit($request, 'contact_ownership.bulk_fix', null, null, $targetClient, $dryRun, $reason, [], [
            'summary' => $summary,
            'results' => $results,
        ]);

        return [
            'client' => $this->clientPayload($targetClient),
            'target_site' => $targetSite ? $this->sitePayload($targetSite) : null,
            'summary' => $summary,
            'audit_id' => $auditId,
            'results' => $results,
        ];
    }

    public function detachContact(
        Client $client,
        Contact $contact,
        bool $dryRun,
        bool $deleteIfOrphan,
        ?string $reason,
        Request $request
    ): array {
        $client->loadMissing('sites');
        $before = $this->ownershipSnapshot($contact);
        $siteIds = $client->sites->pluck('id')->all();
        $clientMorph = (new Client())->getMorphClass();
        $siteMorph = (new ClientSite())->getMorphClass();

        $relationIds = $contact->relations()
            ->where(function ($query) use ($client, $siteIds, $clientMorph, $siteMorph): void {
                $query->where(function ($inner) use ($client, $clientMorph): void {
                    $inner->where('related_type', $clientMorph)
                        ->where('related_id', $client->id);
                });

                if ($siteIds !== []) {
                    $query->orWhere(function ($inner) use ($siteMorph, $siteIds): void {
                        $inner->where('related_type', $siteMorph)
                            ->whereIn('related_id', $siteIds);
                    });
                }
            })
            ->pluck('id')
            ->all();

        $legacyIds = ClientUser::query()
            ->where('contact_id', $contact->id)
            ->whereIn('client_site_id', $siteIds)
            ->pluck('id')
            ->all();

        $plan = [
            'status' => ($relationIds === [] && $legacyIds === []) ? 'no_change' : 'would_detach',
            'delete_relation_ids' => $relationIds,
            'detach_legacy_client_user_ids' => $legacyIds,
            'delete_if_orphan' => $deleteIfOrphan,
        ];

        if ($dryRun || $plan['status'] === 'no_change') {
            $auditId = $this->recordAudit($request, 'contact_ownership.detach', $contact, $client, null, $dryRun, $reason, $before, $plan);

            return [
                'dry_run' => $dryRun,
                'changed' => false,
                'audit_id' => $auditId,
                'plan' => $plan,
                'contact' => $this->contactOwnershipPayload($contact->fresh(['emails', 'phones', 'relations'])),
            ];
        }

        DB::transaction(function () use ($contact, $relationIds, $legacyIds, $deleteIfOrphan): void {
            ContactRelation::query()->whereIn('id', $relationIds)->delete();

            ClientUser::query()
                ->whereIn('id', $legacyIds)
                ->update(['contact_id' => null]);

            $fresh = $contact->fresh();

            if ($deleteIfOrphan && $fresh && $this->isOrphanedContact($fresh)) {
                $fresh->delete();
            }
        });

        $freshContact = Contact::withTrashed()->with(['emails', 'phones', 'relations'])->find($contact->id);
        $after = $freshContact && ! $freshContact->trashed() ? $this->ownershipSnapshot($freshContact) : ['deleted' => true];
        $result = $plan;
        $result['status'] = $freshContact?->trashed() ? 'detached_and_deleted' : 'detached';
        $auditId = $this->recordAudit($request, 'contact_ownership.detach', $freshContact ?: $contact, $client, null, $dryRun, $reason, $before, $result, $after);

        return [
            'dry_run' => false,
            'changed' => true,
            'audit_id' => $auditId,
            'plan' => $result,
            'contact' => $freshContact && ! $freshContact->trashed() ? $this->contactOwnershipPayload($freshContact) : null,
        ];
    }

    private function buildMovePlan(Contact $contact, Client $targetClient, ?ClientSite $targetSite, bool $bulkMode): array
    {
        $contact->loadMissing(['emails', 'phones', 'relations']);

        $clientMorph = (new Client())->getMorphClass();
        $siteMorph = (new ClientSite())->getMorphClass();
        $targetClient->loadMissing('sites');
        $targetSiteIds = $targetClient->sites->pluck('id');

        $clientRelations = $contact->relations->where('related_type', $clientMorph)->values();
        $siteRelations = $contact->relations->where('related_type', $siteMorph)->values();
        $legacyRows = ClientUser::query()->with('site.client')->where('contact_id', $contact->id)->get();
        $currentClientIds = $this->currentClientIds($clientRelations, $siteRelations, $legacyRows);
        $nonTargetClientIds = $currentClientIds->reject(fn (int $clientId) => $clientId === $targetClient->id)->values();
        $conflicts = [];

        if ($bulkMode && $nonTargetClientIds->count() > 1) {
            $conflicts[] = [
                'code' => 'multiple_current_clients',
                'message' => 'Bulk repair will not guess between multiple current Client owners.',
                'client_ids' => $nonTargetClientIds->all(),
            ];
        }

        if ($legacyRows->count() > 1) {
            $conflicts[] = [
                'code' => 'multiple_legacy_client_users',
                'message' => 'The Contact has multiple linked legacy client_users rows. Review before moving.',
                'client_user_ids' => $legacyRows->pluck('id')->all(),
            ];
        }

        if (! $targetSite && ($legacyRows->isNotEmpty() || $siteRelations->isNotEmpty())) {
            $conflicts[] = [
                'code' => 'target_site_missing',
                'message' => 'A target site is required to move site relations or legacy client_users.',
            ];
        }

        if ($targetSite && $legacyRows->count() === 1) {
            $legacyRow = $legacyRows->first();

            if ($legacyRow->client_site_id !== $targetSite->id && $legacyRow->email) {
                $emailConflict = ClientUser::query()
                    ->where('client_site_id', $targetSite->id)
                    ->where('email', $legacyRow->email)
                    ->whereKeyNot($legacyRow->id)
                    ->exists();

                if ($emailConflict) {
                    $conflicts[] = [
                        'code' => 'legacy_email_conflict',
                        'message' => 'The target site already has a legacy client user with this email address.',
                        'email' => $legacyRow->email,
                    ];
                }
            }
        }

        $deleteRelationIds = $clientRelations
            ->reject(fn (ContactRelation $relation) => (int) $relation->related_id === $targetClient->id)
            ->pluck('id')
            ->merge($siteRelations
                ->filter(function (ContactRelation $relation) use ($targetSite, $targetSiteIds): bool {
                    if ($targetSite) {
                        return (int) $relation->related_id !== $targetSite->id;
                    }

                    return ! $targetSiteIds->contains((int) $relation->related_id);
                })
                ->pluck('id'))
            ->values()
            ->all();

        $hasTargetClientRelation = $clientRelations
            ->contains(fn (ContactRelation $relation) => (int) $relation->related_id === $targetClient->id);
        $hasTargetSiteRelation = $targetSite
            ? $siteRelations->contains(fn (ContactRelation $relation) => (int) $relation->related_id === $targetSite->id)
            : true;
        $legacyAlreadyTargeted = $targetSite
            ? $legacyRows->count() <= 1 && ($legacyRows->isEmpty() || (int) $legacyRows->first()->client_site_id === $targetSite->id)
            : $legacyRows->isEmpty();

        $changes = [
            'remove_relation_count' => count($deleteRelationIds),
            'add_target_client_relation' => ! $hasTargetClientRelation,
            'add_target_site_relation' => (bool) $targetSite && ! $hasTargetSiteRelation,
            'move_or_create_legacy_client_user' => (bool) $targetSite && ! $legacyAlreadyTargeted,
            'target_client_id' => $targetClient->id,
            'target_client_number' => $targetClient->client_number,
            'target_site_id' => $targetSite?->id,
        ];

        $status = match (true) {
            $conflicts !== [] => 'conflict',
            $changes['remove_relation_count'] === 0
                && ! $changes['add_target_client_relation']
                && ! $changes['add_target_site_relation']
                && ! $changes['move_or_create_legacy_client_user'] => 'no_change',
            $currentClientIds->isEmpty() => 'would_attach',
            default => 'would_move',
        };

        return [
            'status' => $status,
            'contact_id' => $contact->id,
            'target_client' => $this->clientPayload($targetClient),
            'target_site' => $targetSite ? $this->sitePayload($targetSite) : null,
            'current_client_ids' => $currentClientIds->all(),
            'relation_type' => $this->relationTypeForMove($clientRelations, $siteRelations),
            'conflicts' => $conflicts,
            'changes' => $changes,
            'delete_relation_ids' => $deleteRelationIds,
        ];
    }

    private function currentClientIds(Collection $clientRelations, Collection $siteRelations, Collection $legacyRows): Collection
    {
        $siteIds = $siteRelations->pluck('related_id')->map(fn ($id) => (int) $id)->all();
        $sites = $siteIds === []
            ? collect()
            : ClientSite::query()->whereIn('id', $siteIds)->pluck('client_id');

        return $clientRelations
            ->pluck('related_id')
            ->map(fn ($id) => (int) $id)
            ->merge($sites->map(fn ($id) => (int) $id))
            ->merge($legacyRows->map(fn (ClientUser $clientUser) => (int) $clientUser->site?->client_id)->filter())
            ->filter()
            ->unique()
            ->values();
    }

    private function targetSiteForClient(Client $client, ?int $targetSiteId): ?ClientSite
    {
        $client->loadMissing('sites');

        if ($targetSiteId) {
            $site = ClientSite::query()->whereKey($targetSiteId)->firstOrFail();

            if ((int) $site->client_id !== (int) $client->id) {
                throw ValidationException::withMessages([
                    'target_site_id' => 'The selected target site does not belong to the target client.',
                ]);
            }

            return $site;
        }

        return $client->sites()
            ->where('is_default', true)
            ->orderBy('id')
            ->first()
            ?: $client->sites()->orderBy('id')->first();
    }

    private function syncSingleLegacyBridge(Contact $contact, ClientSite $targetSite): void
    {
        $contact->loadMissing(['emails', 'phones']);
        $primaryEmail = $this->primaryEmail($contact);
        $primaryPhone = $this->primaryPhone($contact);
        $legacyRows = ClientUser::query()->where('contact_id', $contact->id)->orderBy('id')->get();

        if ($legacyRows->count() === 1) {
            $legacyRows->first()->forceFill([
                'client_site_id' => $targetSite->id,
                'name' => $contact->display_name,
                'email' => $primaryEmail,
                'phone' => $primaryPhone,
                'role' => $contact->job_title ?: 'Contact',
                'active' => true,
            ])->save();

            return;
        }

        if ($legacyRows->isEmpty()) {
            ClientUser::query()->create([
                'contact_id' => $contact->id,
                'client_site_id' => $targetSite->id,
                'name' => $contact->display_name,
                'email' => $primaryEmail,
                'phone' => $primaryPhone,
                'role' => $contact->job_title ?: 'Contact',
                'active' => true,
            ]);
        }
    }

    private function relationTypeForMove(Collection $clientRelations, Collection $siteRelations): string
    {
        return (string) (
            $clientRelations->first()?->relation_type
            ?? $siteRelations->first()?->relation_type
            ?? 'contact'
        );
    }

    private function ownershipSnapshot(Contact $contact): array
    {
        $contact->loadMissing(['emails', 'phones', 'relations']);

        return [
            'contact' => [
                'id' => $contact->id,
                'display_name' => $contact->display_name,
                'primary_email' => $this->primaryEmail($contact),
            ],
            'relations' => $contact->relations
                ->map(fn (ContactRelation $relation) => [
                    'id' => $relation->id,
                    'related_type' => $relation->related_type,
                    'related_id' => $relation->related_id,
                    'relation_type' => $relation->relation_type,
                    'is_primary' => (bool) $relation->is_primary,
                ])
                ->values()
                ->all(),
            'legacy_client_users' => ClientUser::query()
                ->with('site.client')
                ->where('contact_id', $contact->id)
                ->get()
                ->map(fn (ClientUser $clientUser) => $this->legacyClientUserPayload($clientUser))
                ->values()
                ->all(),
        ];
    }

    private function contactOwnershipPayload(?Contact $contact): ?array
    {
        if (! $contact) {
            return null;
        }

        $contact->loadMissing(['emails', 'phones', 'relations']);
        $legacyRows = ClientUser::query()
            ->with('site.client')
            ->where('contact_id', $contact->id)
            ->orderBy('id')
            ->get();

        return [
            'id' => $contact->id,
            'display_name' => $contact->display_name,
            'primary_email' => $this->primaryEmail($contact),
            'primary_phone' => $this->primaryPhone($contact),
            'deleted_at' => $contact->deleted_at,
            'relations' => $contact->relations
                ->map(fn (ContactRelation $relation) => $this->relationPayload($relation))
                ->values()
                ->all(),
            'legacy_client_users' => $legacyRows
                ->map(fn (ClientUser $clientUser) => $this->legacyClientUserPayload($clientUser))
                ->values()
                ->all(),
        ];
    }

    private function relationPayload(ContactRelation $relation): array
    {
        $related = $this->relatedPayload($relation);

        return [
            'id' => $relation->id,
            'related_type' => $relation->related_type,
            'related_id' => $relation->related_id,
            'relation_type' => $relation->relation_type,
            'is_primary' => (bool) $relation->is_primary,
            'related' => $related,
        ];
    }

    private function relatedPayload(ContactRelation $relation): ?array
    {
        if ($relation->related_type === (new Client())->getMorphClass()) {
            $client = Client::query()->find($relation->related_id);

            return $client ? $this->clientPayload($client) : null;
        }

        if ($relation->related_type === (new ClientSite())->getMorphClass()) {
            $site = ClientSite::query()->with('client')->find($relation->related_id);

            return $site ? $this->sitePayload($site) : null;
        }

        return null;
    }

    private function legacyClientUserPayload(ClientUser $clientUser): array
    {
        $clientUser->loadMissing('site.client');

        return [
            'id' => $clientUser->id,
            'contact_id' => $clientUser->contact_id,
            'name' => $clientUser->name,
            'email' => $clientUser->email,
            'phone' => $clientUser->phone,
            'role' => $clientUser->role,
            'active' => (bool) $clientUser->active,
            'site' => $clientUser->site ? $this->sitePayload($clientUser->site) : null,
            'client' => $clientUser->site?->client ? $this->clientPayload($clientUser->site->client) : null,
        ];
    }

    private function clientPayload(Client $client): array
    {
        return [
            'id' => $client->id,
            'client_number' => $client->client_number,
            'name' => $client->name,
        ];
    }

    private function sitePayload(ClientSite $site): array
    {
        $site->loadMissing('client');

        return [
            'id' => $site->id,
            'client_id' => $site->client_id,
            'client_number' => $site->client?->client_number,
            'name' => $site->name,
            'is_default' => (bool) $site->is_default,
        ];
    }

    private function primaryEmail(Contact $contact): ?string
    {
        $contact->loadMissing('emails');

        return ($contact->emails->firstWhere('is_primary', true) ?? $contact->emails->first())?->email;
    }

    private function primaryPhone(Contact $contact): ?string
    {
        $contact->loadMissing('phones');

        return ($contact->phones->firstWhere('is_primary', true) ?? $contact->phones->first())?->phone;
    }

    private function isOrphanedContact(Contact $contact): bool
    {
        return ! $contact->relations()->exists()
            && ! ClientUser::query()->where('contact_id', $contact->id)->exists()
            && ! $contact->user()->exists();
    }

    private function recordAudit(
        Request $request,
        string $event,
        ?Contact $contact,
        ?Client $sourceClient,
        ?Client $targetClient,
        bool $dryRun,
        ?string $reason,
        array $before,
        array $result,
        array $after = []
    ): ?int {
        $token = $request->user()?->currentAccessToken();
        $logger = activity('contact_ownership')
            ->event($event)
            ->causedBy($request->user())
            ->withProperties([
                'dry_run' => $dryRun,
                'reason' => $reason,
                'api_token_id' => $token->id ?? null,
                'source_client_id' => $sourceClient?->id,
                'target_client_id' => $targetClient?->id,
                'before' => $before,
                'result' => $result,
                'after' => $after,
            ]);

        if ($contact) {
            $logger->performedOn($contact);
        }

        return $logger->log('Contact ownership repair API call')->id ?? null;
    }
}
