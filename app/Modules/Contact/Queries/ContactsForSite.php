<?php

namespace App\Modules\Contact\Queries;

use App\Models\Clients\ClientSite;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Collection;

class ContactsForSite
{
    /**
     * Return canonical Contact records explicitly related to one Client Site.
     *
     * The client_users bridge is loaded only as compatibility metadata. It is
     * never the source of visible Site ownership.
     */
    public function handle(ClientSite $site): Collection
    {
        $siteType = $site->getMorphClass();

        return Contact::query()
            ->with([
                'emails',
                'phones',
                'relations.related',
                'clientUsers.site',
            ])
            ->whereHas('relations', function ($relations) use ($site, $siteType): void {
                $relations
                    ->where('related_type', $siteType)
                    ->where('related_id', $site->id);
            })
            ->orderBy('display_name')
            ->get()
            ->each(function (Contact $contact) use ($site, $siteType): void {
                $siteRelation = $contact->relations->first(
                    fn ($relation): bool => $relation->related_type === $siteType
                        && (int) $relation->related_id === (int) $site->id
                );
                $bridge = $contact->clientUsers->first(
                    fn ($clientUser): bool => (int) $clientUser->client_site_id === (int) $site->id
                );

                $contact->setRelation('contextSite', $site);
                $contact->setAttribute(
                    'client_context_role',
                    $siteRelation?->relation_type ?: $bridge?->role
                );
                $contact->setAttribute(
                    'is_primary_for_client',
                    (bool) ($bridge?->is_default_for_client)
                );
            });
    }
}
