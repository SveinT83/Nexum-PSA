<?php

namespace App\Modules\Contact\Queries;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Collection;

class ContactsForClient
{
    /**
     * Return the canonical Contact records related to one Client.
     *
     * Compatibility client_users rows are loaded only to provide fallback
     * presentation metadata. They are not used to decide visible ownership.
     */
    public function handle(Client $client): Collection
    {
        $clientType = $client->getMorphClass();
        $siteType = (new ClientSite)->getMorphClass();
        $siteIds = $client->sites()->pluck('id');

        return Contact::query()
            ->with([
                'emails',
                'phones',
                'relations.related',
                'clientUsers.site',
            ])
            ->where(function ($query) use ($client, $clientType, $siteIds, $siteType): void {
                $query->whereHas('relations', function ($relations) use ($client, $clientType): void {
                    $relations
                        ->where('related_type', $clientType)
                        ->where('related_id', $client->id);
                });

                if ($siteIds->isNotEmpty()) {
                    $query->orWhereHas('relations', function ($relations) use ($siteIds, $siteType): void {
                        $relations
                            ->where('related_type', $siteType)
                            ->whereIn('related_id', $siteIds);
                    });
                }
            })
            ->orderBy('display_name')
            ->get()
            ->each(function (Contact $contact) use ($client, $clientType, $siteType): void {
                $clientRelation = $contact->relations->first(
                    fn ($relation): bool => $relation->related_type === $clientType
                        && (int) $relation->related_id === (int) $client->id
                );
                $siteRelation = $contact->relations->first(
                    fn ($relation): bool => $relation->related_type === $siteType
                        && $relation->related instanceof ClientSite
                        && (int) $relation->related->client_id === (int) $client->id
                );
                $bridge = $contact->clientUsers->first(
                    fn ($clientUser): bool => (int) $clientUser->site?->client_id === (int) $client->id
                );

                $isDefaultForClient = $contact->clientUsers->contains(
                    fn ($clientUser): bool => (int) $clientUser->site?->client_id === (int) $client->id
                        && (bool) $clientUser->is_default_for_client
                );
                $contact->setRelation('contextSite', $siteRelation?->related ?: $bridge?->site);
                $contact->setAttribute(
                    'client_context_role',
                    $clientRelation?->relation_type ?: $siteRelation?->relation_type ?: $bridge?->role
                );
                $contact->setAttribute(
                    'is_primary_for_client',
                    $isDefaultForClient
                );
            });
    }
}
