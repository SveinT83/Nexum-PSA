<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaign;
use Illuminate\Support\Carbon;

class AdvanceMarketingCampaignLifecycle
{
    public function __construct(
        private readonly SyncMarketingCampaignRecipients $syncRecipients,
    ) {
    }

    public function handle(MarketingCampaign $campaign): ?string
    {
        $campaign = $campaign->fresh(['emails']) ?: $campaign;

        if (! in_array($campaign->status, ['approved', 'active'], true)) {
            return null;
        }

        $activeEmailIds = $campaign->emails()
            ->where('status', 'active')
            ->pluck('id');

        if ($activeEmailIds->isEmpty()) {
            return null;
        }

        $cycle = max(1, (int) ($campaign->current_cycle ?: 1));
        $cycleRecipients = $campaign->recipients()
            ->where('cycle_number', $cycle)
            ->whereIn('marketing_campaign_email_id', $activeEmailIds);

        if (! (clone $cycleRecipients)->exists()) {
            return null;
        }

        if ((clone $cycleRecipients)->where('status', 'pending')->exists()) {
            return null;
        }

        if (($campaign->completion_behavior ?: 'stop') === 'repeat') {
            $nextCycleAt = $this->nextCycleAt($campaign);

            $campaign->forceFill([
                'status' => 'active',
                'current_cycle' => $cycle + 1,
                'next_cycle_at' => $nextCycleAt,
                'last_cycle_completed_at' => now(),
                'completed_at' => null,
            ])->save();

            $this->syncRecipients->handle($campaign->fresh(['emails.recipients', 'lists.members', 'list.members', 'recipients']));

            return 'repeated';
        }

        $campaign->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'last_cycle_completed_at' => now(),
            'next_cycle_at' => null,
        ])->save();

        return 'completed';
    }

    private function nextCycleAt(MarketingCampaign $campaign): Carbon
    {
        $next = now()->copy();
        $value = max(1, (int) ($campaign->repeat_interval_value ?: 1));
        $unit = array_key_exists($campaign->repeat_interval_unit ?: 'months', MarketingCampaign::SEQUENCE_INTERVAL_UNITS)
            ? ($campaign->repeat_interval_unit ?: 'months')
            : 'months';

        match ($unit) {
            'minutes' => $next->addMinutes($value),
            'hours' => $next->addHours($value),
            'days' => $next->addDays($value),
            'weeks' => $next->addWeeks($value),
            default => $next->addMonthsNoOverflow($value),
        };

        return $next->seconds(0);
    }
}
