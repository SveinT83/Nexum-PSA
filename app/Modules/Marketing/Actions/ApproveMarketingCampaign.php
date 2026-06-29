<?php

namespace App\Modules\Marketing\Actions;

use App\Models\Core\User;
use App\Modules\Marketing\Models\MarketingCampaign;
use Illuminate\Support\Facades\DB;

class ApproveMarketingCampaign
{
    public function __construct(
        private readonly SyncMarketingCampaignRecipients $syncRecipients,
        private readonly ResolveMarketingListMembers $resolveListMembers,
    ) {
    }

    public function handle(MarketingCampaign $campaign, User $approver): int
    {
        return DB::transaction(function () use ($campaign, $approver): int {
            $campaign->forceFill([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ])->save();

            $campaign = $campaign->fresh(['lists', 'list']);

            foreach ($campaign->audienceLists() as $list) {
                $this->resolveListMembers->handle($list);
            }

            return $this->syncRecipients->handle(
                $campaign->fresh(['emails.recipients', 'lists.members', 'list.members', 'recipients'])
            );
        });
    }
}
