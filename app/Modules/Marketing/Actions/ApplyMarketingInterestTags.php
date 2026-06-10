<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaignEvent;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingInterestAssignment;
use App\Modules\Marketing\Models\MarketingInterestTag;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ApplyMarketingInterestTags
{
    public function handle(MarketingCampaignEvent $event, MarketingCampaignRecipient $recipient): array
    {
        $keys = $this->interestKeys($event, $recipient);

        if ($keys === []) {
            return [];
        }

        $tags = MarketingInterestTag::query()
            ->where('is_active', true)
            ->whereIn('key', $keys)
            ->get();

        $tags->each(function (MarketingInterestTag $tag) use ($event, $recipient): void {
            if ($recipient->contact_id) {
                $this->recordAssignment($tag, $event, $recipient->contact_id, null);
            }

            if ($recipient->client_id) {
                $this->recordAssignment($tag, $event, null, $recipient->client_id);
            }
        });

        $metadata = $event->metadata ?? [];
        $metadata['interest_tag_keys'] = $tags->pluck('key')->values()->all();
        $event->forceFill(['metadata' => $metadata])->save();

        return $metadata['interest_tag_keys'];
    }

    private function interestKeys(MarketingCampaignEvent $event, MarketingCampaignRecipient $recipient): array
    {
        $recipient->loadMissing([
            'campaign.list.consentCategory',
            'campaignEmail.template',
        ]);

        if ($event->type === 'open' && $recipient->campaign?->list?->consentCategory?->key === 'newsletter') {
            return ['opened-newsletter'];
        }

        if ($event->type !== 'click') {
            return [];
        }

        $haystack = Str::lower(collect([
            $event->url,
            $recipient->campaign?->name,
            $recipient->campaign?->description,
            $recipient->campaignEmail?->subject_override,
            $recipient->campaignEmail?->template?->name,
            $recipient->campaignEmail?->template?->subject,
        ])->filter()->implode(' '));

        return collect([
            'clicked-security' => ['security', 'sikkerhet', 'cyber', 'ransomware', 'firewall', 'brannmur', 'phishing', 'antivirus', 'edr', 'mdr', 'cve', 'vulnerability', 'sårbarhet'],
            'clicked-website' => ['website', 'webside', 'hjemmeside', 'wordpress', 'hosting', 'webhotell', 'seo', 'domain', 'domene', 'nettbutikk'],
            'clicked-cloud' => ['cloud', 'microsoft', '365', 'exchange', 'azure', 'teams', 'sharepoint', 'onedrive'],
        ])
            ->filter(fn (array $needles): bool => Collection::make($needles)->contains(fn (string $needle): bool => str_contains($haystack, $needle)))
            ->keys()
            ->values()
            ->all();
    }

    private function recordAssignment(MarketingInterestTag $tag, MarketingCampaignEvent $event, ?int $contactId, ?int $clientId): void
    {
        $assignment = MarketingInterestAssignment::query()->firstOrNew([
            'marketing_interest_tag_id' => $tag->id,
            'contact_id' => $contactId,
            'client_id' => $clientId,
        ]);

        if (! $assignment->exists) {
            $assignment->first_event_id = $event->id;
            $assignment->first_seen_at = $event->occurred_at;
            $assignment->event_count = 0;
            $assignment->engagement_score = 0;
        }

        $assignment->forceFill([
            'last_event_id' => $event->id,
            'last_seen_at' => $event->occurred_at,
            'event_count' => ((int) $assignment->event_count) + 1,
            'engagement_score' => ((int) $assignment->engagement_score) + ($event->type === 'click' ? 10 : 2),
            'metadata' => [
                'last_event_type' => $event->type,
                'last_url' => $event->url,
            ],
        ])->save();
    }
}
