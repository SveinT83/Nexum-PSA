<?php

namespace App\Modules\Marketing\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingCampaignRecipientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'marketing_campaign_id' => $this->marketing_campaign_id,
            'marketing_campaign_email_id' => $this->marketing_campaign_email_id,
            'marketing_list_member_id' => $this->marketing_list_member_id,
            'marketing_campaign_delivery_id' => $this->marketing_campaign_delivery_id,
            'cycle_number' => $this->cycle_number,
            'contact_id' => $this->contact_id,
            'client_user_id' => $this->client_user_id,
            'client_id' => $this->client_id,
            'email' => $this->email,
            'name' => $this->name,
            'status' => $this->status,
            'due_at' => $this->due_at,
            'sent_at' => $this->sent_at,
            'claimed_at' => $this->claimed_at,
            'outcome_unknown_at' => $this->outcome_unknown_at,
            'attempts' => $this->attempts,
            'rfc_message_id' => $this->rfc_message_id,
            'last_error' => $this->last_error,
            'metadata' => $this->metadata,
            'delivery' => $this->whenLoaded('delivery', fn () => $this->delivery ? [
                'id' => $this->delivery->id,
                'status' => $this->delivery->status,
                'claimed_at' => $this->delivery->claimed_at,
                'provider_write_started_at' => $this->delivery->provider_write_started_at,
                'sent_at' => $this->delivery->sent_at,
                'outcome_unknown_at' => $this->delivery->outcome_unknown_at,
                'last_error_code' => $this->delivery->last_error_code,
            ] : null),
            'campaign_email' => $this->whenLoaded('campaignEmail', fn () => $this->campaignEmail ? [
                'id' => $this->campaignEmail->id,
                'display_name' => $this->campaignEmail->displayName(),
                'sequence_order' => $this->campaignEmail->sequence_order,
            ] : null),
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'client_number' => $this->client->client_number,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
