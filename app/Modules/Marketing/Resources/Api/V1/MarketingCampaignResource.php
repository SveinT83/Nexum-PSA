<?php

namespace App\Modules\Marketing\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingCampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'marketing_list_id' => $this->marketing_list_id,
            'marketing_list_ids' => $this->whenLoaded(
                'lists',
                fn () => $this->audienceLists()->pluck('id')->values()
            ),
            'email_account_id' => $this->email_account_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'starts_at' => $this->starts_at,
            'batch_size' => $this->batch_size,
            'send_interval_minutes' => $this->send_interval_minutes,
            'sequence_interval_value' => $this->sequence_interval_value,
            'sequence_interval_unit' => $this->sequence_interval_unit,
            'new_recipient_policy' => $this->new_recipient_policy,
            'schedule_frequency' => $this->scheduleFrequency(),
            'schedule_time' => $this->scheduleTime(),
            'send_weekday' => $this->scheduleWeekday(),
            'month_day' => $this->scheduleMonthDay(),
            'send_rhythm_label' => $this->sendRhythmLabel(),
            'track_opens' => (bool) $this->track_opens,
            'track_clicks' => (bool) $this->track_clicks,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'emails_count' => $this->whenCounted('emails'),
            'recipients_count' => $this->whenCounted('recipients'),
            'events_count' => $this->whenCounted('events'),
            'list' => $this->whenLoaded('list', fn () => $this->list ? [
                'id' => $this->list->id,
                'name' => $this->list->name,
                'audience_type' => $this->list->audience_type,
            ] : null),
            'lists' => $this->whenLoaded('lists', fn () => $this->audienceLists()->map(fn ($list): array => [
                'id' => $list->id,
                'name' => $list->name,
                'audience_type' => $list->audience_type,
            ])->values()),
            'email_account' => $this->whenLoaded('emailAccount', fn () => $this->emailAccount ? [
                'id' => $this->emailAccount->id,
                'address' => $this->emailAccount->address,
                'from_name' => $this->emailAccount->from_name,
            ] : null),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
                'email' => $this->approver->email,
            ] : null),
            'emails' => $this->whenLoaded('emails', fn () => MarketingCampaignEmailResource::collection($this->emails)),
            'recipients' => $this->whenLoaded('recipients', fn () => MarketingCampaignRecipientResource::collection($this->recipients)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.marketing.campaigns.show', $this->id),
                'schedule' => route('api.v1.marketing.campaigns.schedule.update', $this->id),
                'approve' => route('api.v1.marketing.campaigns.approve', $this->id),
                'send_due' => route('api.v1.marketing.campaigns.send-due', $this->id),
            ],
        ];
    }
}
