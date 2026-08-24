<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingListMember;
use App\Modules\Marketing\Support\MarketingSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SyncMarketingCampaignRecipients
{
    private const FALLBACK_BATCH_SIZE = 50;

    private const FALLBACK_BATCH_INTERVAL_MINUTES = 15;

    public function __construct(
        private readonly ResolveMarketingCampaignAudienceMembers $audienceMembers,
        private readonly ResolveMarketingCampaignMemberProgress $memberProgress,
        private readonly MatchMarketingCampaignRecipientsByIdentity $identityMatcher,
        private readonly EnrichMarketingCampaignRecipientIdentityEvidence $identityEvidence,
        private readonly NextMarketingCampaignOccurrence $occurrences,
        private readonly MarketingSuppressionGuard $suppressionGuard,
        private readonly MarketingSettings $settings,
    ) {}

    public function handle(MarketingCampaign $campaign): int
    {
        $campaign->loadMissing(['emails', 'lists.members', 'list.members', 'recipients.delivery']);
        $created = 0;
        $members = $this->eligibleMembers($campaign);
        $cycle = max(1, (int) ($campaign->current_cycle ?: 1));
        $recipientIndex = $this->memberProgress->recipientIndex($campaign);
        $settingsPayload = $this->settings->get();

        foreach ($members as $memberIndex => $member) {
            $this->enrichIdentityEvidence($recipientIndex, $member);
            $progress = $this->memberProgress->handle($campaign, $member, null, $recipientIndex);
            $allowedPendingId = null;

            if ($progress['state'] === 'caught_up') {
                $this->deferAllPendingExcept($progress['identity_recipients']);

                continue;
            }

            $campaignEmail = $progress['next_email'];

            if (! $campaignEmail) {
                $this->deferAllPendingExcept($progress['identity_recipients']);

                continue;
            }

            if ($progress['state'] === 'blocked') {
                $this->deferAllPendingExcept($progress['identity_recipients']);

                continue;
            }

            $pending = $progress['current_recipients']
                ->where('status', 'pending')
                ->sortBy('id')
                ->first();
            $reopened = false;

            if (! $pending) {
                $pending = $this->recoverableRecipient(
                    $progress['current_recipients'],
                    $settingsPayload,
                );

                if ($pending) {
                    $pending->forceFill([
                        'status' => 'pending',
                        'due_at' => $this->dueAt(
                            $campaign,
                            $campaignEmail,
                            $memberIndex,
                            now(),
                        ),
                        'last_error' => null,
                    ])->save();
                    $reopened = true;
                }
            }

            if ($progress['current_recipients']->isEmpty()) {
                [$reference, $strictAfter] = $this->scheduleReference($progress, $campaignEmail, now());
                $pending = $campaignEmail->recipients()->create([
                    'marketing_campaign_id' => $campaign->id,
                    'marketing_list_member_id' => $member->id,
                    'cycle_number' => $cycle,
                    'contact_id' => $member->contact_id,
                    'client_user_id' => $member->client_user_id,
                    'client_id' => $member->client_id,
                    'email' => $member->email,
                    'name' => $member->name,
                    'status' => 'pending',
                    'due_at' => $this->dueAt(
                        $campaign,
                        $campaignEmail,
                        $memberIndex,
                        $reference,
                        $strictAfter,
                    ),
                    'tracking_token' => Str::random(48),
                    'metadata' => [
                        'list_member_source_type' => $member->source_type,
                        'list_member_source_id' => $member->source_id,
                        'campaign_cycle' => $cycle,
                        'progression' => 'next_missing_step',
                    ],
                ]);
                $campaign->recipients->push($pending);
                $created++;
            } elseif ($pending && ! $reopened && ! $pending->due_at) {
                // Routine scheduler sync must not move an already scheduled
                // legacy/current row to a later occurrence. Explicit schedule
                // edits use reschedulePending(), while a missing due date is
                // safely repaired here.
                [$reference, $strictAfter] = $this->scheduleReference(
                    $progress,
                    $campaignEmail,
                    now(),
                );
                $minimumDueAt = $this->dueAt(
                    $campaign,
                    $campaignEmail,
                    $memberIndex,
                    $reference,
                    $strictAfter,
                );
                $pending->forceFill(['due_at' => $minimumDueAt])->save();
            }

            if ($pending) {
                $allowedPendingId = (int) $pending->id;
            }

            $this->deferAllPendingExcept(
                $progress['identity_recipients'],
                $allowedPendingId,
            );
        }

        return $created;
    }

    public function reschedulePending(MarketingCampaign $campaign): int
    {
        $campaign->loadMissing(['emails', 'lists.members', 'list.members', 'recipients.delivery']);
        $updated = 0;
        $members = $this->eligibleMembers($campaign);
        $recipientIndex = $this->memberProgress->recipientIndex($campaign);

        foreach ($members as $memberIndex => $member) {
            $progress = $this->memberProgress->handle($campaign, $member, null, $recipientIndex);
            $allowedPendingId = null;

            if ($progress['state'] === 'in_progress' && $progress['next_email']) {
                $pending = $progress['current_recipients']
                    ->where('status', 'pending')
                    ->sortBy('id')
                    ->first();

                if ($pending) {
                    $pending->forceFill([
                        'due_at' => $this->dueAt(
                            $campaign,
                            $progress['next_email'],
                            $memberIndex,
                            now(),
                        ),
                    ])->save();
                    $allowedPendingId = (int) $pending->id;
                    $updated++;
                }
            }

            $updated += $this->deferAllPendingExcept(
                $progress['identity_recipients'],
                $allowedPendingId,
            );
        }

        return $updated;
    }

    public function dueAt(
        MarketingCampaign $campaign,
        MarketingCampaignEmail $email,
        int $memberIndex = 0,
        ?Carbon $reference = null,
        bool $strictAfter = false,
    ): Carbon {
        $reference ??= now();

        if ($email->scheduled_at && $email->scheduled_at->gt($reference)) {
            $reference = $email->scheduled_at->copy();
            $strictAfter = false;
        }

        return $this->occurrences
            ->handle($campaign, $reference, $strictAfter)
            ->addMinutes(max(0, (int) $email->delay_minutes))
            ->addMinutes($this->batchOffsetMinutes($campaign, $memberIndex));
    }

    private function scheduleReference(
        array $progress,
        MarketingCampaignEmail $email,
        Carbon $fallback,
    ): array {
        $lastConfirmedAt = $progress['last_confirmed_at'];

        if (! $lastConfirmedAt) {
            return [$fallback->copy(), false];
        }

        if ($email->created_at && $email->created_at->gt($lastConfirmedAt)) {
            return [$email->created_at->copy(), false];
        }

        return [$lastConfirmedAt->copy(), true];
    }

    private function enrichIdentityEvidence(
        array $recipientIndex,
        MarketingListMember $member,
    ): void {
        foreach ($this->identityMatcher->forMember($recipientIndex, $member) as $recipient) {
            if ($this->identityEvidence->handle($recipient, $member)) {
                continue;
            }

            $recipient->refresh();
            $recipient->load('delivery');
        }
    }

    private function recoverableRecipient(
        Collection $recipients,
        array $settings,
    ): ?MarketingCampaignRecipient {
        return $recipients
            ->filter(
                fn (MarketingCampaignRecipient $recipient): bool => $this->memberProgress->isRecoverableBeforeClaim($recipient)
                    && $this->suppressionGuard->reasonForRecipient($recipient, $settings) === null,
            )
            ->sortBy('id')
            ->first();
    }

    private function deferAllPendingExcept(
        Collection $identityRecipients,
        ?int $allowedPendingId = null,
    ): int {
        $updated = 0;

        foreach ($identityRecipients->where('status', 'pending') as $recipient) {
            if ($allowedPendingId !== null && (int) $recipient->id === $allowedPendingId) {
                continue;
            }

            if ($recipient->due_at === null) {
                continue;
            }

            $recipient->forceFill(['due_at' => null])->save();
            $updated++;
        }

        return $updated;
    }

    private function eligibleMembers(MarketingCampaign $campaign): Collection
    {
        return $this->audienceMembers->handle($campaign);
    }

    private function batchOffsetMinutes(MarketingCampaign $campaign, int $memberIndex): int
    {
        $batchSize = max(1, (int) ($campaign->batch_size ?: self::FALLBACK_BATCH_SIZE));
        $interval = max(1, (int) ($campaign->send_interval_minutes ?: self::FALLBACK_BATCH_INTERVAL_MINUTES));

        return intdiv(max(0, $memberIndex), $batchSize) * $interval;
    }
}
