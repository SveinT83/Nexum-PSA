<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingListMember;
use Illuminate\Support\Collection;

class ResolveMarketingCampaignAudienceMembers
{
    public function handle(MarketingCampaign $campaign): Collection
    {
        $campaign->loadMissing(['lists.members', 'list.members']);

        $seenContactIds = [];
        $seenClientUserIds = [];
        $seenEmails = [];

        return $campaign->audienceLists()
            ->flatMap(fn ($list) => $list->members->whereIn('status', ['eligible', 'active']))
            ->filter(fn (MarketingListMember $member): bool => filled($member->email))
            ->filter(function (MarketingListMember $member) use (&$seenContactIds, &$seenClientUserIds, &$seenEmails): bool {
                $email = $this->normalizeEmail($member->email);

                if (
                    ($member->contact_id && isset($seenContactIds[(int) $member->contact_id]))
                    || ($member->client_user_id && isset($seenClientUserIds[(int) $member->client_user_id]))
                    || isset($seenEmails[$email])
                ) {
                    return false;
                }

                if ($member->contact_id) {
                    $seenContactIds[(int) $member->contact_id] = true;
                }

                if ($member->client_user_id) {
                    $seenClientUserIds[(int) $member->client_user_id] = true;
                }

                $seenEmails[$email] = true;

                return true;
            })
            ->values();
    }

    private function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }
}
