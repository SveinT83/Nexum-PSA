<?php

namespace App\Modules\Marketing\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingCampaignEmailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'marketing_campaign_id' => $this->marketing_campaign_id,
            'email_template_id' => $this->email_template_id,
            'name' => $this->name,
            'display_name' => $this->displayName(),
            'template_snapshot_name' => $this->template_snapshot_name,
            'source_template_name' => $this->sourceTemplateName(),
            'sequence_order' => $this->sequence_order,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at,
            'delay_minutes' => $this->delay_minutes,
            'subject_override' => $this->subject_override,
            'subject_snapshot' => $this->subject_snapshot,
            'effective_subject' => $this->effectiveSubject(),
            'body_html_snapshot' => $this->body_html_snapshot,
            'body_text_snapshot' => $this->body_text_snapshot,
            'variables_snapshot' => $this->variables_snapshot,
            'metadata' => $this->metadata,
            'recipients_count' => $this->whenCounted('recipients'),
            'sent_recipients_count' => $this->when(isset($this->sent_recipients_count), $this->sent_recipients_count),
            'open_events_count' => $this->when(isset($this->open_events_count), $this->open_events_count),
            'click_events_count' => $this->when(isset($this->click_events_count), $this->click_events_count),
            'template' => $this->whenLoaded('template', fn () => $this->template ? [
                'id' => $this->template->id,
                'name' => $this->template->name,
                'scope' => $this->template->scope,
                'subject' => $this->template->subject,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'campaign' => route('api.v1.marketing.campaigns.show', $this->marketing_campaign_id),
                'update' => route('api.v1.marketing.campaigns.emails.update', [
                    'campaign' => $this->marketing_campaign_id,
                    'email' => $this->id,
                ]),
                'test_send' => route('api.v1.marketing.campaigns.emails.test-send', [
                    'campaign' => $this->marketing_campaign_id,
                    'email' => $this->id,
                ]),
            ],
        ];
    }
}
