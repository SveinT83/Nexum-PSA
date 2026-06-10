<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Models\MarketingListMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SyncMarketingCampaignRecipients
{
    public function handle(MarketingCampaign $campaign): int
    {
        $campaign->loadMissing(['emails', 'list.members']);
        $created = 0;

        foreach ($campaign->emails->where('status', 'active')->sortBy('sequence_order') as $campaignEmail) {
            foreach ($campaign->list->members->where('status', 'eligible') as $member) {
                $recipient = $campaignEmail->recipients()
                    ->where('marketing_list_member_id', $member->id)
                    ->first();

                if ($recipient) {
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
                    'due_at' => $this->dueAt($campaign, $campaignEmail),
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

    public function dueAt(MarketingCampaign $campaign, MarketingCampaignEmail $email): Carbon
    {
        if ($email->scheduled_at) {
            return $email->scheduled_at;
        }

        return ($campaign->starts_at ?: now())->copy()->addMinutes((int) $email->delay_minutes);
    }
}
