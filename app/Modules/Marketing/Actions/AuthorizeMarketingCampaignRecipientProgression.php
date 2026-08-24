<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingListMember;
use Illuminate\Support\Facades\DB;

class AuthorizeMarketingCampaignRecipientProgression
{
    private array $recipientIndexes = [];

    private array $audienceIdentityIndexes = [];

    public function __construct(
        private readonly ResolveMarketingCampaignMemberProgress $memberProgress,
        private readonly ResolveMarketingCampaignAudienceMembers $audienceMembers,
        private readonly MatchMarketingCampaignRecipientsByIdentity $identityMatcher,
    ) {}

    public function handle(
        MarketingCampaignRecipient $recipient,
        ?MarketingCampaign $campaignContext = null,
    ): bool {
        if (! $campaignContext) {
            $recipient = $recipient->fresh() ?: $recipient;
            $campaignContext = MarketingCampaign::query()
                ->with(['emails', 'recipients.delivery', 'lists.members', 'list.members'])
                ->find($recipient->marketing_campaign_id);
        } else {
            $campaignContext->loadMissing(['emails', 'recipients.delivery', 'lists.members', 'list.members']);
        }

        if ($recipient->status !== 'pending' || ! $recipient->due_at) {
            return false;
        }

        $campaign = $campaignContext;

        if (
            ! $campaign
            || (int) $campaign->id !== (int) $recipient->marketing_campaign_id
            || ! in_array($campaign->status, ['approved', 'active'], true)
        ) {
            return false;
        }

        if (! $this->isStillEligible($campaign, $recipient)) {
            $this->deferPendingRecipient($recipient);

            return false;
        }

        // The queued recipient is the frozen delivery identity. A mutable list
        // member may have been refreshed or repointed since enrollment.
        $member = new MarketingListMember([
            'contact_id' => $recipient->contact_id,
            'client_user_id' => $recipient->client_user_id,
            'client_id' => $recipient->client_id,
            'email' => $recipient->email,
            'name' => $recipient->name,
            'status' => 'eligible',
        ]);
        $progress = $this->memberProgress->handle(
            $campaign,
            $member,
            null,
            $this->recipientIndex($campaign),
        );

        if (
            ($progress['review_error_code'] ?? null)
                === EnrichMarketingCampaignRecipientIdentityEvidence::REVIEW_ERROR_CODE
        ) {
            $this->markIdentityAmbiguousForReview($recipient);

            return false;
        }

        if (
            $progress['state'] !== 'in_progress'
            || ! $progress['next_email']
            || (int) $progress['next_email']->id !== (int) $recipient->marketing_campaign_email_id
        ) {
            return false;
        }

        $firstPending = $progress['current_recipients']
            ->where('status', 'pending')
            ->filter(fn (MarketingCampaignRecipient $current): bool => $current->due_at !== null)
            ->sortBy('id')
            ->first();

        return (int) $firstPending?->id === (int) $recipient->id;
    }

    private function recipientIndex(MarketingCampaign $campaign): array
    {
        $key = spl_object_id($campaign);

        return $this->recipientIndexes[$key] ??= $this->memberProgress->recipientIndex($campaign);
    }

    private function isStillEligible(
        MarketingCampaign $campaign,
        MarketingCampaignRecipient $recipient,
    ): bool {
        $index = $this->audienceIdentityIndex($campaign);
        $email = $this->identityMatcher->normalizeEmail($recipient->email);

        return ($recipient->contact_id && isset($index['contact'][(int) $recipient->contact_id]))
            || ($recipient->client_user_id && isset($index['client_user'][(int) $recipient->client_user_id]))
            || ($email !== '' && isset($index['email'][$email]));
    }

    private function audienceIdentityIndex(MarketingCampaign $campaign): array
    {
        $key = spl_object_id($campaign);

        if (isset($this->audienceIdentityIndexes[$key])) {
            return $this->audienceIdentityIndexes[$key];
        }

        $index = [
            'contact' => [],
            'client_user' => [],
            'email' => [],
        ];

        foreach ($this->audienceMembers->handle($campaign) as $member) {
            if ($member->contact_id) {
                $index['contact'][(int) $member->contact_id] = true;
            }

            if ($member->client_user_id) {
                $index['client_user'][(int) $member->client_user_id] = true;
            }

            $email = $this->identityMatcher->normalizeEmail($member->email);

            if ($email !== '') {
                $index['email'][$email] = true;
            }
        }

        return $this->audienceIdentityIndexes[$key] = $index;
    }

    private function deferPendingRecipient(MarketingCampaignRecipient $recipient): void
    {
        $updated = MarketingCampaignRecipient::query()
            ->whereKey($recipient->id)
            ->where('status', 'pending')
            ->whereNotNull('due_at')
            ->update(['due_at' => null]);

        if ($updated > 0) {
            $recipient->forceFill(['due_at' => null]);
        }
    }

    private function markIdentityAmbiguousForReview(
        MarketingCampaignRecipient $recipient,
    ): void {
        DB::transaction(function () use ($recipient): void {
            $locked = MarketingCampaignRecipient::query()
                ->lockForUpdate()
                ->find($recipient->id);

            if (! $locked || $locked->status !== 'pending') {
                return;
            }

            $metadata = $locked->metadata ?? [];
            $metadata['delivery_invariant'] = array_merge(
                (array) ($metadata['delivery_invariant'] ?? []),
                [
                    'error_code' => EnrichMarketingCampaignRecipientIdentityEvidence::REVIEW_ERROR_CODE,
                    'review_reason' => 'conflicting_delivery_identity',
                    'automatic_replay_allowed' => false,
                ],
            );
            $locked->forceFill([
                'status' => 'failed',
                'due_at' => null,
                'last_error' => 'Stable recipient identity matches conflicting delivery evidence. Automatic transmission is blocked pending review.',
                'metadata' => $metadata,
            ])->save();

            $recipient->forceFill($locked->only([
                'status',
                'due_at',
                'last_error',
                'metadata',
            ]));
        }, 3);
    }
}
