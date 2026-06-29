<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaign;

class CountMarketingCampaignAudienceRecipients
{
    public function __construct(
        private readonly ResolveMarketingCampaignAudienceMembers $audienceMembers,
    ) {
    }

    public function handle(MarketingCampaign $campaign): int
    {
        return $this->audienceMembers->handle($campaign)->count();
    }
}
