<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaignEvent;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Signal\Actions\RecordSignal;
use Illuminate\Http\Request;

class RecordMarketingCampaignEvent
{
    public function __construct(
        private readonly RecordSignal $signals,
        private readonly ApplyMarketingInterestTags $interestTags,
    )
    {
    }

    public function handle(MarketingCampaignRecipient $recipient, string $type, ?string $url = null, ?Request $request = null): MarketingCampaignEvent
    {
        $event = MarketingCampaignEvent::query()->create([
            'marketing_campaign_id' => $recipient->marketing_campaign_id,
            'marketing_campaign_email_id' => $recipient->marketing_campaign_email_id,
            'marketing_campaign_recipient_id' => $recipient->id,
            'contact_id' => $recipient->contact_id,
            'client_id' => $recipient->client_id,
            'type' => $type,
            'url' => $url,
            'metadata' => [
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ],
            'occurred_at' => now(),
        ]);

        $interestTagKeys = $this->interestTags->handle($event, $recipient);

        $this->signals->handle([
            'source_domain' => 'marketing',
            'source_type' => $event->getMorphClass(),
            'source_id' => $event->id,
            'subject_type' => $recipient->contact?->getMorphClass(),
            'subject_id' => $recipient->contact_id,
            'contact_id' => $recipient->contact_id,
            'client_id' => $recipient->client_id,
            'signal_type' => match ($type) {
                'open' => 'marketing_open',
                'click' => 'marketing_click',
                'unsubscribe' => 'unsubscribe',
                default => 'marketing_'.$type,
            },
            'severity' => $type === 'unsubscribe' ? 'warning' : 'info',
            'confidence' => 100,
            'summary' => $this->summary($type, $url),
            'payload' => [
                'marketing_campaign_id' => $recipient->marketing_campaign_id,
                'marketing_campaign_email_id' => $recipient->marketing_campaign_email_id,
                'marketing_campaign_recipient_id' => $recipient->id,
                'url' => $url,
                'event_type' => $type,
                'interest_tag_keys' => $interestTagKeys,
            ],
            'occurred_at' => $event->occurred_at,
        ]);

        return $event;
    }

    private function summary(string $type, ?string $url): string
    {
        return match ($type) {
            'open' => 'Marketing email opened.',
            'click' => 'Marketing email link clicked: '.($url ?: 'unknown URL'),
            'unsubscribe' => 'Marketing unsubscribe requested.',
            default => 'Marketing event recorded: '.$type,
        };
    }
}
