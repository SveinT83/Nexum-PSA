<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Exceptions\AmbiguousMarketingCampaignDeliveryIdentity;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingListMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ResolveMarketingCampaignMemberProgress
{
    public function __construct(
        private readonly MatchMarketingCampaignRecipientsByIdentity $identityMatcher,
        private readonly NextMarketingCampaignOccurrence $occurrences,
        private readonly FindMarketingCampaignDeliveryForRecipient $deliveries,
    ) {}

    /**
     * @return array{
     *     state: 'in_progress'|'caught_up'|'blocked',
     *     next_email: MarketingCampaignEmail|null,
     *     current_recipients: Collection,
     *     applicable_emails: Collection,
     *     identity_recipients: Collection,
     *     last_confirmed_at: Carbon|null,
     *     review_error_code?: string
     * }
     */
    public function handle(
        MarketingCampaign $campaign,
        MarketingListMember $member,
        ?Carbon $at = null,
        ?array $recipientIndex = null,
    ): array {
        $campaign->loadMissing(['emails', 'recipients.delivery']);
        $at ??= now();
        $recipientIndex ??= $this->recipientIndex($campaign);

        $identityRecipients = $this->identityMatcher->forMember($recipientIndex, $member);
        $activeEmails = $campaign->emails
            ->where('status', 'active')
            ->sortBy([
                ['sequence_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
        $applicableEmails = $this->applicableEmails(
            $campaign,
            $activeEmails,
            $identityRecipients,
            $at,
        );

        if ($this->hasAmbiguousDeliveryIdentity($campaign, $member, $identityRecipients)) {
            return [
                'state' => 'blocked',
                'next_email' => null,
                'current_recipients' => $identityRecipients,
                'applicable_emails' => $applicableEmails,
                'identity_recipients' => $identityRecipients,
                'last_confirmed_at' => null,
                'review_error_code' => EnrichMarketingCampaignRecipientIdentityEvidence::REVIEW_ERROR_CODE,
            ];
        }

        $reviewBlockedRecipients = $identityRecipients
            ->filter(fn (MarketingCampaignRecipient $recipient): bool => $this->requiresReview($recipient))
            ->values();

        if ($reviewBlockedRecipients->isNotEmpty()) {
            return [
                'state' => 'blocked',
                'next_email' => null,
                'current_recipients' => $reviewBlockedRecipients,
                'applicable_emails' => $applicableEmails,
                'identity_recipients' => $identityRecipients,
                'last_confirmed_at' => null,
            ];
        }

        $lastConfirmedAt = null;

        foreach ($applicableEmails as $campaignEmail) {
            $matching = $identityRecipients
                ->filter(
                    fn (MarketingCampaignRecipient $recipient): bool => (int) $recipient->marketing_campaign_email_id === (int) $campaignEmail->id,
                )
                ->values();
            $resolved = $matching->map(fn (MarketingCampaignRecipient $recipient): array => [
                'recipient' => $recipient,
                // Every consumed historical/runtime row is linked during the
                // additive backfill or claim transaction. Matching all stable
                // recipient identities brings the canonical delivery into this
                // eager-loaded collection without a query per step.
                'delivery' => $recipient->delivery,
            ]);
            $confirmedAt = $this->confirmedAt($resolved);

            if ($confirmedAt) {
                if (! $lastConfirmedAt || $confirmedAt->gt($lastConfirmedAt)) {
                    $lastConfirmedAt = $confirmedAt;
                }

                continue;
            }

            return [
                'state' => $matching->isNotEmpty() && $this->isBlocked($resolved)
                    ? 'blocked'
                    : 'in_progress',
                'next_email' => $campaignEmail,
                'current_recipients' => $matching,
                'applicable_emails' => $applicableEmails,
                'identity_recipients' => $identityRecipients,
                'last_confirmed_at' => $lastConfirmedAt,
            ];
        }

        return [
            'state' => 'caught_up',
            'next_email' => null,
            'current_recipients' => collect(),
            'applicable_emails' => $applicableEmails,
            'identity_recipients' => $identityRecipients,
            'last_confirmed_at' => $lastConfirmedAt,
        ];
    }

    public function recipientIndex(MarketingCampaign $campaign): array
    {
        $campaign->loadMissing('recipients.delivery');

        return $this->identityMatcher->index($campaign->recipients);
    }

    private function applicableEmails(
        MarketingCampaign $campaign,
        Collection $activeEmails,
        Collection $identityRecipients,
        Carbon $at,
    ): Collection {
        if (
            ($campaign->new_recipient_policy ?: 'start_at_first_email') !== 'join_current_step'
            || $activeEmails->isEmpty()
        ) {
            return $activeEmails;
        }

        $emailsById = $campaign->emails->keyBy(fn (MarketingCampaignEmail $email): int => (int) $email->id);
        $firstExistingOrder = $identityRecipients
            ->map(function (MarketingCampaignRecipient $recipient) use ($emailsById): ?int {
                $email = $emailsById->get((int) $recipient->marketing_campaign_email_id);

                return $email ? (int) $email->sequence_order : null;
            })
            ->filter(fn (?int $order): bool => $order !== null)
            ->min();

        if ($firstExistingOrder !== null) {
            return $activeEmails
                ->filter(fn (MarketingCampaignEmail $email): bool => (int) $email->sequence_order >= $firstExistingOrder)
                ->values();
        }

        $currentOrder = $this->currentJoinOrder($campaign, $activeEmails, $at);

        return $activeEmails
            ->filter(fn (MarketingCampaignEmail $email): bool => (int) $email->sequence_order >= $currentOrder)
            ->values();
    }

    private function currentJoinOrder(
        MarketingCampaign $campaign,
        Collection $activeEmails,
        Carbon $at,
    ): int {
        if (! $campaign->starts_at) {
            return (int) $activeEmails->last()->sequence_order;
        }

        foreach ($activeEmails as $email) {
            $plannedAt = $this->occurrences
                ->addIntervals(
                    $campaign,
                    $campaign->starts_at->copy(),
                    max(0, ((int) $email->sequence_order) - 1),
                )
                ->addMinutes(max(0, (int) $email->delay_minutes));

            if ($plannedAt->gte($at)) {
                return (int) $email->sequence_order;
            }
        }

        return (int) $activeEmails->last()->sequence_order;
    }

    private function confirmedAt(Collection $resolved): ?Carbon
    {
        $confirmedAt = null;

        foreach ($resolved as $entry) {
            $recipient = $entry['recipient'];
            $delivery = $entry['delivery'];

            if ($delivery) {
                if ($delivery->status !== 'sent') {
                    continue;
                }

                $sentAt = $delivery->sent_at
                    ?: $delivery->updated_at
                    ?: $recipient->sent_at
                    ?: $recipient->updated_at
                    ?: $recipient->created_at;
            } elseif ($recipient->status === 'sent') {
                $sentAt = $recipient->sent_at
                    ?: $recipient->updated_at
                    ?: $recipient->created_at;
            } else {
                continue;
            }

            if ($sentAt && (! $confirmedAt || $sentAt->gt($confirmedAt))) {
                $confirmedAt = $sentAt->copy();
            }
        }

        return $confirmedAt;
    }

    /**
     * Detect conflicting ledger evidence before selecting a consumed step.
     *
     * The cheap in-memory candidate check avoids a finder query before there is
     * linked delivery evidence. Once a linked ledger exists, the canonical
     * finder must also consider orphaned ledger keys that are not represented
     * by the eager-loaded recipient collection.
     */
    private function hasAmbiguousDeliveryIdentity(
        MarketingCampaign $campaign,
        MarketingListMember $member,
        Collection $identityRecipients,
    ): bool {
        foreach ($campaign->emails as $campaignEmail) {
            $possibleDeliveryIds = $identityRecipients
                ->filter(
                    fn (MarketingCampaignRecipient $recipient): bool => (int) $recipient->marketing_campaign_email_id === (int) $campaignEmail->id,
                )
                ->pluck('marketing_campaign_delivery_id')
                ->filter()
                ->map(fn ($deliveryId): int => (int) $deliveryId)
                ->unique();

            if ($possibleDeliveryIds->isEmpty()) {
                continue;
            }

            $candidate = new MarketingCampaignRecipient([
                'marketing_campaign_email_id' => $campaignEmail->id,
                'marketing_list_member_id' => $member->id,
                'contact_id' => $member->contact_id,
                'client_user_id' => $member->client_user_id,
                'email' => $member->email,
            ]);

            try {
                $this->deliveries->handle($candidate);
            } catch (AmbiguousMarketingCampaignDeliveryIdentity) {
                return true;
            }
        }

        return false;
    }

    public function isRecoverableBeforeClaim(MarketingCampaignRecipient $recipient): bool
    {
        if (
            $recipient->delivery
            || ! in_array($recipient->status, ['failed', 'suppressed'], true)
        ) {
            return false;
        }

        $metadata = $recipient->metadata ?? [];
        $deliveryInvariant = (array) ($metadata['delivery_invariant'] ?? []);
        $errorCode = $deliveryInvariant['error_code'] ?? ($metadata['error_code'] ?? null);
        $automaticReplayAllowed = $deliveryInvariant['automatic_replay_allowed']
            ?? ($metadata['automatic_replay_allowed'] ?? null);

        return $errorCode !== EnrichMarketingCampaignRecipientIdentityEvidence::REVIEW_ERROR_CODE
            && $automaticReplayAllowed !== false;
    }

    private function requiresReview(MarketingCampaignRecipient $recipient): bool
    {
        $metadata = $recipient->metadata ?? [];
        $deliveryInvariant = (array) ($metadata['delivery_invariant'] ?? []);

        if (
            ($deliveryInvariant['error_code'] ?? null)
                === EnrichMarketingCampaignRecipientIdentityEvidence::REVIEW_ERROR_CODE
        ) {
            return true;
        }

        if (
            $recipient->delivery
            && in_array($recipient->delivery->status, ['claimed', 'provider_write_started', 'outcome_unknown'], true)
        ) {
            return true;
        }

        return in_array($recipient->status, ['claimed', 'provider_write_started', 'outcome_unknown'], true);
    }

    private function isBlocked(Collection $resolved): bool
    {
        foreach ($resolved as $entry) {
            $recipient = $entry['recipient'];
            $delivery = $entry['delivery'];

            if ($delivery) {
                if (in_array($delivery->status, ['claimed', 'provider_write_started', 'outcome_unknown'], true)) {
                    return true;
                }

                if ($delivery->status !== 'sent') {
                    return true;
                }
            }

            if (
                ! $delivery
                && (
                    $recipient->status === 'pending'
                    || $this->isRecoverableBeforeClaim($recipient)
                )
            ) {
                continue;
            }

            if ($recipient->status !== 'sent') {
                return true;
            }
        }

        return false;
    }
}
