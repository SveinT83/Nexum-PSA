<?php

namespace App\Modules\Marketing\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Contact\Models\Contact;
use App\Modules\LeadIntelligence\Models\ContactMarketingEligibility;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Models\MarketingListMember;
use App\Modules\Marketing\Support\MarketingSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ResolveMarketingListMembers
{
    public function __construct(private readonly MarketingSettings $settings)
    {
    }

    public function handle(MarketingList $list): int
    {
        $settings = $this->settings->get();
        $criteria = $this->criteria($list);
        $resolved = collect()
            ->concat($this->manualContactRecipients($settings, $criteria))
            ->concat($this->manualClientUserRecipients($settings, $criteria))
            ->concat($this->contactRecipients($settings, $criteria))
            ->concat($this->legacyClientUserRecipients($settings, $criteria))
            ->concat($this->leadIntelligenceRecipients($settings, $criteria, $list));

        return DB::transaction(function () use ($list, $resolved, $criteria): int {
            $preservedMembers = $this->preservedExternalMembers($list, $criteria);
            $preservedMemberIds = $preservedMembers->pluck('id');
            $preservedEmails = $preservedMembers
                ->pluck('email')
                ->map(fn (?string $email): string => strtolower((string) $email))
                ->filter()
                ->values();

            $deleteQuery = $list->members();

            if ($preservedMemberIds->isNotEmpty()) {
                $deleteQuery->whereNotIn('id', $preservedMemberIds);
            }

            $deleteQuery->delete();

            $members = $resolved
                ->unique(fn (array $recipient): string => strtolower($recipient['email']))
                ->reject(fn (array $recipient): bool => $preservedEmails->contains(strtolower($recipient['email'])))
                ->values();

            $members->each(fn (array $recipient) => $list->members()->create($recipient));

            $list->forceFill(['last_resolved_at' => now()])->save();

            return $preservedMembers->count() + $members->count();
        });
    }

    private function preservedExternalMembers(MarketingList $list, array $criteria): Collection
    {
        $excludedContactIds = $criteria['excluded_contact_ids'];

        return $list->members()
            ->get()
            ->filter(function (MarketingListMember $member) use ($excludedContactIds): bool {
                if (($member->metadata['source'] ?? null) !== 'lead_intelligence') {
                    return false;
                }

                return ! $member->contact_id || ! in_array((int) $member->contact_id, $excludedContactIds, true);
            })
            ->values();
    }

    private function contactRecipients(array $settings, array $criteria)
    {
        $contactTagIds = $criteria['contact_tag_ids'];
        $excludedContactIds = $criteria['excluded_contact_ids'];

        if ($criteria['audience_type'] === 'manual_contacts') {
            return collect();
        }

        $query = Contact::query()
            ->with([
                'addresses',
                'clientUser',
                'clientUser.site.client',
                'emails' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id'),
                'relations',
            ])
            ->where('status', 'active')
            ->where('do_not_email', false)
            ->when($excludedContactIds !== [], fn ($query) => $query->whereNotIn('id', $excludedContactIds))
            ->when(
                $contactTagIds !== [],
                fn ($query) => $query->whereHas('tags', fn ($tagQuery) => $tagQuery->whereIn('tags.id', $contactTagIds)),
            )
            ->when(
                $settings['consent_mode'] === 'explicit_opt_in',
                fn ($query) => $query->where('marketing_consent', true),
            )
            ->whereHas('emails');

        $this->applyContactSegmentCriteria($query, $criteria);

        return $query
            ->get()
            ->filter(fn (Contact $contact): bool => $this->allowsClientByContractSetting($contact->relations->firstWhere('related_type', (new Client())->getMorphClass())?->related_id, $settings))
            ->map(function (Contact $contact) {
                $email = $contact->emails->first();
                $clientId = $contact->relations->firstWhere('related_type', (new Client())->getMorphClass())?->related_id
                    ?? $contact->clientUser?->site?->client_id;

                return [
                    'source_type' => 'contact',
                    'source_id' => $contact->id,
                    'contact_id' => $contact->id,
                    'client_user_id' => $contact->clientUser?->id,
                    'client_id' => $clientId,
                    'email' => $email->email,
                    'name' => $contact->display_name,
                    'status' => 'eligible',
                    'metadata' => [
                        'source' => 'contact',
                        'email_label' => $email->label,
                    ],
                ];
            });
    }

    private function manualClientUserRecipients(array $settings, array $criteria)
    {
        if ($criteria['manual_client_user_ids'] === []) {
            return collect();
        }

        return ClientUser::query()
            ->with('site.client')
            ->whereIn('id', $criteria['manual_client_user_ids'])
            ->where('active', true)
            ->whereNotNull('email')
            ->get()
            ->filter(fn (ClientUser $clientUser): bool => $this->allowsClientByContractSetting($clientUser->site?->client_id, $settings))
            ->map(fn (ClientUser $clientUser): array => $this->clientUserRecipientPayload($clientUser, 'manual_client_user'));
    }

    private function legacyClientUserRecipients(array $settings, array $criteria)
    {
        if ($criteria['audience_type'] === 'manual_contacts') {
            return collect();
        }

        if ($criteria['contact_tag_ids'] !== []) {
            return collect();
        }

        $query = ClientUser::query()
            ->with('site.client')
            ->whereNull('contact_id')
            ->where('active', true)
            ->whereNotNull('email')
            ->when(
                $criteria['client_tag_ids'] !== [],
                fn ($query) => $query->whereHas('site.client.tags', fn ($tagQuery) => $tagQuery->whereIn('tags.id', $criteria['client_tag_ids'])),
            );

        $this->applyClientUserSegmentCriteria($query, $criteria);

        return $query
            ->get()
            ->filter(fn (ClientUser $clientUser): bool => $this->allowsClientByContractSetting($clientUser->site?->client_id, $settings))
            ->map(fn (ClientUser $clientUser): array => $this->clientUserRecipientPayload($clientUser, 'client_user'));
    }

    private function leadIntelligenceRecipients(array $settings, array $criteria, MarketingList $list)
    {
        $excludedContactIds = $criteria['excluded_contact_ids'];

        return ContactMarketingEligibility::query()
            ->with([
                'contact.emails' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id'),
                'contact.clientUser',
                'client',
            ])
            ->where('eligible', true)
            ->whereNotNull('contact_id')
            ->get()
            ->filter(fn (ContactMarketingEligibility $eligibility): bool => in_array(
                (int) $list->id,
                collect((array) ($eligibility->metadata['recommended_marketing_lists'] ?? []))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter()
                    ->all(),
                true,
            ))
            ->filter(function (ContactMarketingEligibility $eligibility) use ($settings, $excludedContactIds): bool {
                $contact = $eligibility->contact;

                if (! $contact || $contact->status !== 'active' || $contact->do_not_email) {
                    return false;
                }

                if (in_array((int) $contact->id, $excludedContactIds, true)) {
                    return false;
                }

                if ($settings['consent_mode'] === 'explicit_opt_in' && ! $contact->marketing_consent) {
                    return false;
                }

                return filled($eligibility->email);
            })
            ->map(function (ContactMarketingEligibility $eligibility): array {
                $contact = $eligibility->contact;

                return [
                    'source_type' => 'contact',
                    'source_id' => $contact->id,
                    'contact_id' => $contact->id,
                    'client_user_id' => $contact->clientUser?->id,
                    'client_id' => $eligibility->client_id,
                    'email' => $eligibility->email,
                    'name' => $contact->display_name,
                    'status' => 'eligible',
                    'metadata' => [
                        'source' => 'lead_intelligence',
                        'eligibility_id' => $eligibility->id,
                        'source_evidence_id' => $eligibility->source_evidence_id,
                    ],
                ];
            });
    }

    private function manualContactRecipients(array $settings, array $criteria)
    {
        if ($criteria['manual_contact_ids'] === []) {
            return collect();
        }

        $clientMorph = (new Client())->getMorphClass();

        return Contact::query()
            ->with([
                'clientUser',
                'emails' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id'),
                'relations' => fn ($query) => $query->where('related_type', $clientMorph),
            ])
            ->whereIn('id', $criteria['manual_contact_ids'])
            ->whereNotIn('id', $criteria['excluded_contact_ids'])
            ->where('status', 'active')
            ->where('do_not_email', false)
            ->when(
                $settings['consent_mode'] === 'explicit_opt_in',
                fn ($query) => $query->where('marketing_consent', true),
            )
            ->whereHas('emails')
            ->get()
            ->filter(fn (Contact $contact): bool => $this->allowsClientByContractSetting($contact->relations->first()?->related_id, $settings))
            ->map(function (Contact $contact) {
                $email = $contact->emails->first();
                $clientId = $contact->relations->first()?->related_id;

                return [
                    'source_type' => 'manual_contact',
                    'source_id' => $contact->id,
                    'contact_id' => $contact->id,
                    'client_user_id' => $contact->clientUser?->id,
                    'client_id' => $clientId,
                    'email' => $email->email,
                    'name' => $contact->display_name,
                    'status' => 'eligible',
                    'metadata' => [
                        'source' => 'manual_contact',
                        'email_label' => $email->label,
                    ],
                ];
            });
    }

    private function applyContactSegmentCriteria($query, array $criteria): void
    {
        if ($this->hasClientCriteria($criteria)) {
            $clientMorph = (new Client())->getMorphClass();

            $query->whereHas('relations', function ($relationQuery) use ($clientMorph, $criteria): void {
                $relationQuery
                    ->where('related_type', $clientMorph)
                    ->whereIn('related_id', $this->clientQueryForCriteria($criteria)->select('id'));
            });
        }

        if ($this->hasLocationCriteria($criteria)) {
            $siteMorph = (new ClientSite())->getMorphClass();
            $clientMorph = (new Client())->getMorphClass();

            $query->where(function ($locationQuery) use ($criteria, $siteMorph, $clientMorph): void {
                $locationQuery
                    ->whereHas('addresses', fn ($addressQuery) => $this->applyLocationCriteria($addressQuery, $criteria))
                    ->orWhereHas('clientUser', fn ($clientUserQuery) => $this->applyClientUserLocationCriteria($clientUserQuery, $criteria))
                    ->orWhereHas('relations', function ($relationQuery) use ($criteria, $siteMorph): void {
                        $relationQuery
                            ->where('related_type', $siteMorph)
                            ->whereIn('related_id', ClientSite::query()
                                ->tap(fn ($siteQuery) => $this->applyLocationCriteria($siteQuery, $criteria))
                                ->select('id'));
                    })
                    ->orWhereHas('relations', function ($relationQuery) use ($criteria, $clientMorph): void {
                        $relationQuery
                            ->where('related_type', $clientMorph)
                            ->whereIn('related_id', Client::query()
                                ->whereHas('sites', fn ($siteQuery) => $this->applyLocationCriteria($siteQuery, $criteria))
                                ->select('id'));
                    });
            });
        }
    }

    private function applyClientUserSegmentCriteria($query, array $criteria): void
    {
        if ($this->hasClientCriteria($criteria)) {
            $query->whereHas('site.client', fn ($clientQuery) => $this->applyClientCriteria($clientQuery, $criteria));
        }

        if ($this->hasLocationCriteria($criteria)) {
            $query->where(fn ($locationQuery) => $this->applyClientUserLocationCriteria($locationQuery, $criteria));
        }
    }

    private function applyClientUserLocationCriteria($query, array $criteria): void
    {
        $query->where(function ($locationQuery) use ($criteria): void {
            $locationQuery
                ->where(fn ($clientUserQuery) => $this->applyLocationCriteria($clientUserQuery, $criteria))
                ->orWhereHas('site', fn ($siteQuery) => $this->applyLocationCriteria($siteQuery, $criteria));
        });
    }

    private function clientQueryForCriteria(array $criteria)
    {
        return Client::query()->tap(fn ($query) => $this->applyClientCriteria($query, $criteria));
    }

    private function applyClientCriteria($query, array $criteria): void
    {
        if ($criteria['client_tag_ids'] !== []) {
            $query->whereHas('tags', fn ($tagQuery) => $tagQuery->whereIn('tags.id', $criteria['client_tag_ids']));
        }

        if ($criteria['sales_category_ids'] !== []) {
            $query->whereIn('sales_category_id', $criteria['sales_category_ids']);
        }

        match ($criteria['contract_filter']) {
            'with_contract' => $query->has('contracts'),
            'without_contract' => $query->doesntHave('contracts'),
            'won_contract' => $query->whereHas('contracts', fn ($contractQuery) => $contractQuery->where('approval_status', 'won')),
            'active_contract' => $query->whereHas('contracts', fn ($contractQuery) => $this->activeContractQuery($contractQuery)),
            'without_active_contract' => $query->whereDoesntHave('contracts', fn ($contractQuery) => $this->activeContractQuery($contractQuery)),
            default => null,
        };
    }

    private function applyLocationCriteria($query, array $criteria): void
    {
        if ($criteria['postal_codes'] !== []) {
            $query->whereIn(DB::raw('lower(zip)'), $criteria['postal_codes']);
        }

        if ($criteria['counties'] !== []) {
            $query->whereIn(DB::raw('lower(county)'), $criteria['counties']);
        }

        if ($criteria['countries'] !== []) {
            $query->whereIn(DB::raw('lower(country)'), $criteria['countries']);
        }
    }

    private function activeContractQuery($query): void
    {
        $query
            ->whereIn('approval_status', ['approved', 'won'])
            ->whereDate('start_date', '<=', now()->toDateString())
            ->where(function ($dateQuery): void {
                $dateQuery->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', now()->toDateString());
            });
    }

    private function clientUserRecipientPayload(ClientUser $clientUser, string $source): array
    {
        return [
            'source_type' => 'client_user',
            'source_id' => $clientUser->id,
            'contact_id' => $clientUser->contact_id,
            'client_user_id' => $clientUser->id,
            'client_id' => $clientUser->site?->client_id,
            'email' => $clientUser->email,
            'name' => $clientUser->name,
            'status' => 'eligible',
            'metadata' => [
                'source' => $source,
                'site_id' => $clientUser->client_site_id,
            ],
        ];
    }

    private function hasClientCriteria(array $criteria): bool
    {
        return $criteria['client_tag_ids'] !== []
            || $criteria['sales_category_ids'] !== []
            || $criteria['contract_filter'] !== 'any';
    }

    private function hasLocationCriteria(array $criteria): bool
    {
        return $criteria['postal_codes'] !== []
            || $criteria['counties'] !== []
            || $criteria['countries'] !== [];
    }

    private function allowsClientByContractSetting(?int $clientId, array $settings): bool
    {
        if ($settings['active_contract_clients_eligible'] || ! $clientId) {
            return true;
        }

        return ! Contracts::query()
            ->where('client_id', $clientId)
            ->whereIn('approval_status', ['approved', 'won'])
            ->whereDate('start_date', '<=', now()->toDateString())
            ->where(function ($query): void {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', now()->toDateString());
            })
            ->exists();
    }

    private function criteria(MarketingList $list): array
    {
        $criteria = $list->segment_criteria ?? [];
        $contractFilter = $criteria['contract_filter'] ?? 'any';

        return [
            'audience_type' => in_array($criteria['audience_type'] ?? 'all_business_contacts', ['all_business_contacts', 'manual_contacts'], true)
                ? $criteria['audience_type']
                : 'all_business_contacts',
            'contact_tag_ids' => collect($criteria['contact_tag_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'client_tag_ids' => collect($criteria['client_tag_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'manual_contact_ids' => collect($criteria['manual_contact_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'manual_client_user_ids' => collect($criteria['manual_client_user_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'postal_codes' => $this->stringCriteria($criteria, 'postal_codes'),
            'counties' => $this->stringCriteria($criteria, 'counties'),
            'countries' => $this->stringCriteria($criteria, 'countries'),
            'sales_category_ids' => collect($criteria['sales_category_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'contract_filter' => in_array($contractFilter, [
                'any',
                'with_contract',
                'without_contract',
                'active_contract',
                'without_active_contract',
                'won_contract',
            ], true) ? $contractFilter : 'any',
            'excluded_contact_ids' => collect($criteria['excluded_contact_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    private function stringCriteria(array $criteria, string $key): array
    {
        return collect((array) ($criteria[$key] ?? []))
            ->flatMap(fn ($value): array => is_string($value) ? preg_split('/[\r\n,]+/', $value) ?: [] : [$value])
            ->map(fn ($value): string => mb_strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
