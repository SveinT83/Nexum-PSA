<?php

namespace App\Modules\Telephony\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactPhone;

class ResolveTelephonyCallerContext
{
    public function __construct(
        private readonly NormalizePhoneNumber $normalizePhone,
    ) {
    }

    public function handle(?string $normalizedPhone): array
    {
        if (! $normalizedPhone) {
            return $this->emptyContext();
        }

        $contact = $this->contactByPhone($normalizedPhone);
        $clientUser = $contact ? $this->clientUserForContact($contact) : null;

        if (! $clientUser) {
            $clientUser = $this->legacyClientUserByPhone($normalizedPhone);
            $contact ??= $clientUser?->contact;
        }

        $site = $clientUser?->site;
        $client = $site?->client;

        if ($contact && (! $site || ! $client)) {
            [$client, $site] = $this->contextFromRelations($contact, $client, $site);
        }

        return [
            'contact' => $contact,
            'client_user' => $clientUser,
            'client' => $client,
            'site' => $site,
        ];
    }

    private function emptyContext(): array
    {
        return [
            'contact' => null,
            'client_user' => null,
            'client' => null,
            'site' => null,
        ];
    }

    private function contactByPhone(string $normalizedPhone): ?Contact
    {
        return ContactPhone::query()
            ->with(['contact.emails', 'contact.phones', 'contact.relations'])
            ->get()
            ->first(fn (ContactPhone $phone): bool => $this->normalizePhone->handle($phone->phone) === $normalizedPhone)
            ?->contact;
    }

    private function clientUserForContact(Contact $contact): ?ClientUser
    {
        return ClientUser::query()
            ->with('site.client')
            ->where('contact_id', $contact->id)
            ->where('active', true)
            ->orderByDesc('is_default_for_client')
            ->orderByDesc('is_default_for_site')
            ->orderBy('name')
            ->first();
    }

    private function legacyClientUserByPhone(string $normalizedPhone): ?ClientUser
    {
        return ClientUser::query()
            ->with(['site.client', 'contact'])
            ->where('active', true)
            ->get()
            ->first(fn (ClientUser $clientUser): bool => $this->normalizePhone->handle($clientUser->phone) === $normalizedPhone);
    }

    private function contextFromRelations(Contact $contact, ?Client $client, ?ClientSite $site): array
    {
        $contact->loadMissing('relations');
        $clientType = (new Client())->getMorphClass();
        $siteType = (new ClientSite())->getMorphClass();

        if (! $site) {
            $siteRelation = $contact->relations
                ->where('related_type', $siteType)
                ->sortByDesc('is_primary')
                ->first();

            if ($siteRelation) {
                $site = ClientSite::query()->with('client')->find($siteRelation->related_id);
                $client ??= $site?->client;
            }
        }

        if (! $client) {
            $clientRelation = $contact->relations
                ->where('related_type', $clientType)
                ->sortByDesc('is_primary')
                ->first();

            if ($clientRelation) {
                $client = Client::query()->find($clientRelation->related_id);
            }
        }

        return [$client, $site];
    }
}
