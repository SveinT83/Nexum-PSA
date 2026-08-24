<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\LeadIntelligence\Models\MarketingSuppressionEntry;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignDelivery;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingListMember;
use App\Modules\Marketing\Support\MarketingSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SummarizeMarketingCampaignRecipientProgress
{
    public function __construct(
        private readonly ResolveMarketingCampaignAudienceMembers $audienceMembers,
        private readonly ResolveMarketingCampaignMemberProgress $memberProgress,
        private readonly MarketingSettings $settings,
    ) {}

    /**
     * @return array{
     *     eligible_recipients: int,
     *     in_progress: int,
     *     caught_up: int,
     *     blocked: int,
     *     next_due: Carbon|null
     * }
     */
    public function handle(MarketingCampaign $campaign): array
    {
        $campaign = $campaign->fresh(['emails', 'recipients.delivery', 'lists.members.contact', 'list.members.contact'])
            ?: $campaign;
        $campaign->loadMissing(['emails', 'recipients.delivery', 'lists.members.contact', 'list.members.contact']);
        $settings = $this->settings->get();
        $members = $this->audienceMembers->handle($campaign);
        $suppressionIndex = $this->suppressionIndex($members);
        $recipientIndex = $this->memberProgress->recipientIndex($campaign);
        $representedReviewDeliveryIds = [];
        $summary = [
            'eligible_recipients' => 0,
            'in_progress' => 0,
            'caught_up' => 0,
            'blocked' => 0,
            'next_due' => null,
        ];

        foreach ($members as $member) {
            if (! $this->isEligible($member, $settings, $suppressionIndex)) {
                continue;
            }

            $summary['eligible_recipients']++;
            $progress = $this->memberProgress->handle($campaign, $member, null, $recipientIndex);
            $summary[$progress['state']]++;

            if ($progress['state'] === 'blocked') {
                foreach ($this->reviewDeliveryIds($progress['identity_recipients']) as $deliveryId) {
                    $representedReviewDeliveryIds[$deliveryId] = true;
                }
            }

            if ($progress['state'] !== 'in_progress') {
                continue;
            }

            $dueAt = $progress['current_recipients']
                ->filter(fn (MarketingCampaignRecipient $recipient): bool => $recipient->status === 'pending' && $recipient->due_at !== null)
                ->sortBy('due_at')
                ->first()
                ?->due_at;

            if ($dueAt && (! $summary['next_due'] || $dueAt->lt($summary['next_due']))) {
                $summary['next_due'] = $dueAt->copy();
            }
        }

        $unrepresentedReviewDeliveries = $this->reviewDeliveryIds($campaign->recipients)
            ->reject(fn (int $deliveryId): bool => isset($representedReviewDeliveryIds[$deliveryId]));

        $summary['blocked'] += $unrepresentedReviewDeliveries->count();

        return $summary;
    }

    private function isEligible(
        MarketingListMember $member,
        array $settings,
        array $suppressionIndex,
    ): bool {
        $member->loadMissing('contact');

        if ($member->contact?->do_not_email) {
            return false;
        }

        if (
            ($settings['consent_mode'] ?? 'opt_out') === 'explicit_opt_in'
            && $member->contact
            && ! $member->contact->marketing_consent
        ) {
            return false;
        }

        $email = $this->normalizeEmail($member->email);
        $domain = $email !== '' && str_contains($email, '@') ? Str::after($email, '@') : '';

        return ! isset($suppressionIndex['email'][$email])
            && ($domain === '' || ! isset($suppressionIndex['domain'][$domain]))
            && (! $member->contact_id || ! isset($suppressionIndex['contact'][(int) $member->contact_id]))
            && (! $member->client_id || ! isset($suppressionIndex['client'][(int) $member->client_id]));
    }

    /**
     * @return array{
     *     email: array<string, true>,
     *     domain: array<string, true>,
     *     contact: array<int, true>,
     *     client: array<int, true>
     * }
     */
    private function suppressionIndex(Collection $members): array
    {
        $emails = $members
            ->map(fn (MarketingListMember $member): string => $this->normalizeEmail($member->email))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $domains = collect($emails)
            ->filter(fn (string $email): bool => str_contains($email, '@'))
            ->map(fn (string $email): string => Str::after($email, '@'))
            ->unique()
            ->values()
            ->all();
        $contactIds = $members->pluck('contact_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $clientIds = $members->pluck('client_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $index = [
            'email' => [],
            'domain' => [],
            'contact' => [],
            'client' => [],
        ];

        if ($emails === [] && $domains === [] && $contactIds === [] && $clientIds === []) {
            return $index;
        }

        $entries = MarketingSuppressionEntry::query()
            ->where(function ($query) use ($emails, $domains, $contactIds, $clientIds): void {
                $query->whereRaw('1 = 0');

                if ($emails !== []) {
                    $query->orWhereIn(DB::raw('LOWER(email)'), $emails);
                }

                if ($domains !== []) {
                    $query->orWhereIn(DB::raw('LOWER(domain)'), $domains);
                }

                if ($contactIds !== []) {
                    $query->orWhereIn('contact_id', $contactIds);
                }

                if ($clientIds !== []) {
                    $query->orWhereIn('client_id', $clientIds);
                }
            })
            ->get(['email', 'domain', 'contact_id', 'client_id']);

        foreach ($entries as $entry) {
            $email = $this->normalizeEmail($entry->email);
            $domain = $this->normalizeEmail($entry->domain);

            if ($email !== '') {
                $index['email'][$email] = true;
            }

            if ($domain !== '') {
                $index['domain'][$domain] = true;
            }

            if ($entry->contact_id) {
                $index['contact'][(int) $entry->contact_id] = true;
            }

            if ($entry->client_id) {
                $index['client'][(int) $entry->client_id] = true;
            }
        }

        return $index;
    }

    private function reviewDeliveryIds(Collection $recipients): Collection
    {
        return $recipients
            ->map(fn (MarketingCampaignRecipient $recipient): ?MarketingCampaignDelivery => $recipient->delivery)
            ->filter(
                fn (?MarketingCampaignDelivery $delivery): bool => $delivery
                    && (
                        in_array($delivery->status, [
                            MarketingCampaignDelivery::STATUS_CLAIMED,
                            MarketingCampaignDelivery::STATUS_PROVIDER_WRITE_STARTED,
                            MarketingCampaignDelivery::STATUS_OUTCOME_UNKNOWN,
                        ], true)
                        || data_get($delivery->metadata, 'identity_enrichment_review.error_code')
                            === EnrichMarketingCampaignRecipientIdentityEvidence::REVIEW_ERROR_CODE
                    ),
            )
            ->pluck('id')
            ->filter()
            ->map(fn ($deliveryId): int => (int) $deliveryId)
            ->unique()
            ->values();
    }

    private function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }
}
