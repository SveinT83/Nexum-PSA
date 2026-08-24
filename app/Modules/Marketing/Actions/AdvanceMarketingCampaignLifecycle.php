<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaign;

class AdvanceMarketingCampaignLifecycle
{
    public function __construct(
        private readonly SyncMarketingCampaignRecipients $syncRecipients,
        private readonly SummarizeMarketingCampaignRecipientProgress $recipientProgress,
    ) {}

    public function handle(MarketingCampaign $campaign): ?string
    {
        $campaign = $campaign->fresh(['emails']) ?: $campaign;

        if (! in_array($campaign->status, ['approved', 'active'], true)) {
            return null;
        }

        if (! $campaign->emails()->where('status', 'active')->exists()) {
            return null;
        }

        MarketingCampaign::query()
            ->whereKey($campaign->id)
            ->where('status', 'approved')
            ->update(['status' => 'active']);

        $campaign = $campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients.delivery']);

        if (! $campaign || $campaign->status !== 'active') {
            return null;
        }

        $created = $this->syncRecipients->handle(
            $campaign,
        );
        $summary = $this->recipientProgress->handle(
            $campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients.delivery']) ?: $campaign,
        );

        if ($created > 0) {
            return 'progressed';
        }

        if ($summary['blocked'] > 0) {
            return 'blocked';
        }

        if ($summary['in_progress'] > 0) {
            return 'in_progress';
        }

        return 'idle';
    }
}
