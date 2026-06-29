<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingListMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SyncMarketingCampaignRecipients
{
    private const FALLBACK_BATCH_SIZE = 50;

    private const FALLBACK_BATCH_INTERVAL_MINUTES = 15;

    public function handle(MarketingCampaign $campaign): int
    {
        $campaign->loadMissing(['emails.recipients', 'lists.members', 'list.members', 'recipients']);
        $created = 0;
        $emails = $campaign->emails->where('status', 'active')->sortBy('sequence_order')->values();
        $members = $this->eligibleMembers($campaign);

        foreach ($members as $memberIndex => $member) {
            $existingForMember = $this->existingRecipientsForMember($campaign->recipients, $member);
            $policy = $campaign->new_recipient_policy ?: 'start_at_first_email';
            $skipPastSequence = $policy === 'join_current_step';
            $journeyStart = $this->journeyStart($campaign, $existingForMember, ! $skipPastSequence);

            foreach ($emails as $campaignEmail) {
                $recipient = $this->recipientForMember($campaignEmail, $member);

                if ($recipient) {
                    $this->refreshRecipientMemberLink($recipient, $member);
                    continue;
                }

                $dueAt = $this->dueAt($campaign, $campaignEmail, $memberIndex, $journeyStart);

                if ($skipPastSequence && $dueAt->lt(now())) {
                    continue;
                }

                $campaignEmail->recipients()->create([
                    'marketing_campaign_id' => $campaign->id,
                    'marketing_list_member_id' => $member->id,
                    'contact_id' => $member->contact_id,
                    'client_user_id' => $member->client_user_id,
                    'client_id' => $member->client_id,
                    'email' => $member->email,
                    'name' => $member->name,
                    'status' => 'pending',
                    'due_at' => $dueAt,
                    'tracking_token' => Str::random(48),
                    'metadata' => [
                        'list_member_source_type' => $member->source_type,
                        'list_member_source_id' => $member->source_id,
                    ],
                ]);

                $created++;
            }
        }

        return $created;
    }

    public function reschedulePending(MarketingCampaign $campaign): int
    {
        $campaign->loadMissing(['emails.recipients', 'lists.members', 'list.members', 'recipients']);
        $updated = 0;
        $members = $this->eligibleMembers($campaign);

        foreach ($members as $memberIndex => $member) {
            $existingForMember = $this->existingRecipientsForMember($campaign->recipients, $member);
            $journeyStart = $this->journeyStart($campaign, $existingForMember, false);

            foreach ($existingForMember->where('status', 'pending') as $recipient) {
                $email = $campaign->emails->firstWhere('id', $recipient->marketing_campaign_email_id);

                if (! $email || $email->status !== 'active') {
                    continue;
                }

                $recipient->forceFill([
                    'due_at' => $this->dueAt($campaign, $email, $memberIndex, $journeyStart),
                ])->save();
                $updated++;
            }
        }

        return $updated;
    }

    private function recipientForMember(MarketingCampaignEmail $campaignEmail, MarketingListMember $member): ?MarketingCampaignRecipient
    {
        $email = $this->normalizeEmail($member->email);

        return $campaignEmail->recipients()
            ->where(function ($query) use ($member, $email): void {
                $query->where('marketing_list_member_id', $member->id);

                if ($member->contact_id) {
                    $query->orWhere('contact_id', $member->contact_id);
                }

                if ($member->client_user_id) {
                    $query->orWhere('client_user_id', $member->client_user_id);
                }

                $query->orWhereRaw('lower(email) = ?', [$email]);
            })
            ->first();
    }

    private function existingRecipientsForMember(Collection $recipients, MarketingListMember $member): Collection
    {
        return $recipients->filter(function (MarketingCampaignRecipient $recipient) use ($member): bool {
            return (int) $recipient->marketing_list_member_id === (int) $member->id
                || ($member->contact_id && (int) $recipient->contact_id === (int) $member->contact_id)
                || ($member->client_user_id && (int) $recipient->client_user_id === (int) $member->client_user_id)
                || $this->normalizeEmail($recipient->email) === $this->normalizeEmail($member->email);
        })->values();
    }

    private function eligibleMembers(MarketingCampaign $campaign): Collection
    {
        $seenContactIds = [];
        $seenClientUserIds = [];
        $seenEmails = [];

        return $campaign->audienceLists()
            ->flatMap(fn ($list) => $list->members->whereIn('status', ['eligible', 'active']))
            ->filter(fn (MarketingListMember $member): bool => filled($member->email))
            ->filter(function (MarketingListMember $member) use (&$seenContactIds, &$seenClientUserIds, &$seenEmails): bool {
                $email = $this->normalizeEmail($member->email);

                if (
                    ($member->contact_id && isset($seenContactIds[(int) $member->contact_id]))
                    || ($member->client_user_id && isset($seenClientUserIds[(int) $member->client_user_id]))
                    || isset($seenEmails[$email])
                ) {
                    return false;
                }

                if ($member->contact_id) {
                    $seenContactIds[(int) $member->contact_id] = true;
                }

                if ($member->client_user_id) {
                    $seenClientUserIds[(int) $member->client_user_id] = true;
                }

                $seenEmails[$email] = true;

                return true;
            })
            ->values();
    }

    private function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    private function refreshRecipientMemberLink(MarketingCampaignRecipient $recipient, MarketingListMember $member): void
    {
        if ((int) $recipient->marketing_list_member_id === (int) $member->id) {
            return;
        }

        $recipient->forceFill([
            'marketing_list_member_id' => $member->id,
            'contact_id' => $member->contact_id,
            'client_user_id' => $member->client_user_id,
            'client_id' => $member->client_id,
        ])->save();
    }

    public function dueAt(
        MarketingCampaign $campaign,
        MarketingCampaignEmail $email,
        int $memberIndex = 0,
        ?Carbon $journeyStart = null,
    ): Carbon {
        $dueAt = $email->scheduled_at
            ? $email->scheduled_at->copy()
            : $this->sequenceDueAt($campaign, $email, $journeyStart);

        return $dueAt->addMinutes($this->batchOffsetMinutes($campaign, $memberIndex));
    }

    private function journeyStart(MarketingCampaign $campaign, $existingForMember, bool $newRecipientsStartNow = true): Carbon
    {
        $firstSent = collect($existingForMember)
            ->where('status', 'sent')
            ->sortBy('sent_at')
            ->first();

        if ($firstSent?->sent_at) {
            return $firstSent->sent_at->copy();
        }

        if ($newRecipientsStartNow && (! $campaign->starts_at || $campaign->starts_at->isPast())) {
            return now();
        }

        return ($campaign->starts_at ?: now())->copy();
    }

    private function sequenceDueAt(MarketingCampaign $campaign, MarketingCampaignEmail $email, ?Carbon $journeyStart): Carbon
    {
        $dueAt = ($journeyStart ?: $campaign->starts_at ?: now())->copy();
        $steps = max(0, ((int) $email->sequence_order) - 1);
        $intervalValue = max(1, (int) ($campaign->sequence_interval_value ?: 1)) * $steps;
        $unit = array_key_exists($campaign->sequence_interval_unit ?: 'days', MarketingCampaign::SEQUENCE_INTERVAL_UNITS)
            ? ($campaign->sequence_interval_unit ?: 'days')
            : 'days';

        match ($unit) {
            'minutes' => $dueAt->addMinutes($intervalValue),
            'hours' => $dueAt->addHours($intervalValue),
            'weeks' => $dueAt->addWeeks($intervalValue),
            'months' => $dueAt->addMonthsNoOverflow($intervalValue),
            default => $dueAt->addDays($intervalValue),
        };

        if ((int) $email->delay_minutes > 0) {
            $dueAt->addMinutes((int) $email->delay_minutes);
        }

        return $dueAt;
    }

    private function batchOffsetMinutes(MarketingCampaign $campaign, int $memberIndex): int
    {
        $batchSize = max(1, (int) ($campaign->batch_size ?: self::FALLBACK_BATCH_SIZE));
        $interval = max(1, (int) ($campaign->send_interval_minutes ?: self::FALLBACK_BATCH_INTERVAL_MINUTES));

        return intdiv(max(0, $memberIndex), $batchSize) * $interval;
    }
}
