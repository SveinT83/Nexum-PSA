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
            'contact_id' => $this->contact_id,
            'client_user_id' => $this->client_user_id,
            'client_id' => $this->client_id,
            'email' => $this->email,
            'name' => $this->name,
            'status' => $this->status,
            'due_at' => $this->due_at,
            'sent_at' => $this->sent_at,
            'attempts' => $this->attempts,
            'rfc_message_id' => $this->rfc_message_id,
            'last_error' => $this->last_error,
            'metadata' => $this->metadata,
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
