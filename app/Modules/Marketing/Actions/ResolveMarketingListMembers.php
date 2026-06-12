<?php

namespace App\Modules\Marketing\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientUser;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Contact\Models\Contact;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Support\MarketingSettings;
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
            ->concat($this->contactRecipients($settings, $criteria))
            ->concat($this->legacyClientUserRecipients($settings, $criteria));

        return DB::transaction(function () use ($list, $resolved): int {
            $list->members()->delete();

            $members = $resolved
                ->unique(fn (array $recipient): string => strtolower($recipient['email']))
                ->values();

            $members->each(fn (array $recipient) => $list->members()->create($recipient));

            $list->forceFill(['last_resolved_at' => now()])->save();

            return $members->count();
        });
    }

    private function contactRecipients(array $settings, array $criteria)
    {
        $clientMorph = (new Client())->getMorphClass();
        $contactTagIds = $criteria['contact_tag_ids'];
        $clientTagIds = $criteria['client_tag_ids'];
        $excludedContactIds = $criteria['excluded_contact_ids'];

        if ($criteria['audience_type'] === 'manual_contacts') {
            return collect();
        }

        return Contact::query()
            ->with([
                'clientUser',
                'emails' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id'),
                'relations' => fn ($query) => $query->where('related_type', $clientMorph),
            ])
            ->where('status', 'active')
            ->where('do_not_email', false)
            ->when($excludedContactIds !== [], fn ($query) => $query->whereNotIn('id', $excludedContactIds))
            ->when(
                $contactTagIds !== [],
                fn ($query) => $query->whereHas('tags', fn ($tagQuery) => $tagQuery->whereIn('tags.id', $contactTagIds)),
            )
            ->when(
                $clientTagIds !== [],
                fn ($query) => $query->whereHas('relations', function ($relationQuery) use ($clientMorph, $clientTagIds): void {
                    $relationQuery
                        ->where('related_type', $clientMorph)
                        ->whereIn('related_id', Client::query()
                            ->whereHas('tags', fn ($tagQuery) => $tagQuery->whereIn('tags.id', $clientTagIds))
                            ->select('id'));
                }),
            )
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

    private function legacyClientUserRecipients(array $settings, array $criteria)
    {
        if ($criteria['audience_type'] === 'manual_contacts') {
            return collect();
        }

        if ($criteria['contact_tag_ids'] !== []) {
            return collect();
        }

        return ClientUser::query()
            ->with('site.client')
            ->whereNull('contact_id')
            ->where('active', true)
            ->whereNotNull('email')
            ->when(
                $criteria['client_tag_ids'] !== [],
                fn ($query) => $query->whereHas('site.client.tags', fn ($tagQuery) => $tagQuery->whereIn('tags.id', $criteria['client_tag_ids'])),
            )
            ->get()
            ->filter(fn (ClientUser $clientUser): bool => $this->allowsClientByContractSetting($clientUser->site?->client_id, $settings))
            ->map(fn (ClientUser $clientUser): array => [
                'source_type' => 'client_user',
                'source_id' => $clientUser->id,
                'contact_id' => null,
                'client_user_id' => $clientUser->id,
                'client_id' => $clientUser->site?->client_id,
                'email' => $clientUser->email,
                'name' => $clientUser->name,
                'status' => 'eligible',
                'metadata' => [
                    'source' => 'client_user',
                    'site_id' => $clientUser->client_site_id,
                ],
            ]);
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
            'excluded_contact_ids' => collect($criteria['excluded_contact_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }
}
