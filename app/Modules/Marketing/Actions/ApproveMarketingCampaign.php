<?php

namespace App\Modules\Marketing\Actions;

use App\Models\Core\User;
use App\Modules\Marketing\Models\MarketingCampaign;
use Illuminate\Support\Facades\DB;

class ApproveMarketingCampaign
{
    public function __construct(private readonly SyncMarketingCampaignRecipients $syncRecipients)
    {
    }

    public function handle(MarketingCampaign $campaign, User $approver): int
    {
        return DB::transaction(function () use ($campaign, $approver): int {
            $campaign->forceFill([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ])->save();

            return $this->syncRecipients->handle($campaign->fresh());
        });
    }
}
