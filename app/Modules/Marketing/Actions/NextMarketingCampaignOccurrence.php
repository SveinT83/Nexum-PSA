<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaign;
use Illuminate\Support\Carbon;

class NextMarketingCampaignOccurrence
{
    public function handle(
        MarketingCampaign $campaign,
        Carbon $reference,
        bool $strictAfter = false,
    ): Carbon {
        $reference = $reference->copy();
        $anchor = $campaign->starts_at?->copy();

        if (! $anchor) {
            return $strictAfter
                ? $this->addIntervals($campaign, $reference, 1)
                : $reference;
        }

        if ($reference->lt($anchor)) {
            return $anchor;
        }

        $intervals = $this->intervalIndexAtOrBeforeReference($campaign, $anchor, $reference);
        $candidate = $this->addIntervals($campaign, $anchor, $intervals);

        while ($candidate->lt($reference) || ($strictAfter && $candidate->lte($reference))) {
            $intervals++;
            $candidate = $this->addIntervals($campaign, $anchor, $intervals);
        }

        return $candidate;
    }

    public function addIntervals(
        MarketingCampaign $campaign,
        Carbon $date,
        int $intervals,
    ): Carbon {
        $intervals = max(0, $intervals);
        $value = max(1, (int) ($campaign->sequence_interval_value ?: 1)) * $intervals;
        $unit = $this->unit($campaign);

        return match ($unit) {
            'minutes' => $date->copy()->addMinutes($value),
            'hours' => $date->copy()->addHours($value),
            'weeks' => $date->copy()->addWeeks($value),
            'months' => $date->copy()->addMonthsNoOverflow($value),
            default => $date->copy()->addDays($value),
        };
    }

    private function intervalIndexAtOrBeforeReference(
        MarketingCampaign $campaign,
        Carbon $anchor,
        Carbon $reference,
    ): int {
        $intervalValue = max(1, (int) ($campaign->sequence_interval_value ?: 1));
        $elapsedUnits = match ($this->unit($campaign)) {
            'minutes' => (int) floor($anchor->diffInMinutes($reference)),
            'hours' => (int) floor($anchor->diffInHours($reference)),
            'weeks' => intdiv((int) floor($anchor->diffInDays($reference)), 7),
            'months' => (($reference->year - $anchor->year) * 12)
                + ($reference->month - $anchor->month),
            default => (int) floor($anchor->diffInDays($reference)),
        };

        return intdiv(max(0, $elapsedUnits), $intervalValue);
    }

    private function unit(MarketingCampaign $campaign): string
    {
        $unit = $campaign->sequence_interval_unit ?: 'days';

        return array_key_exists($unit, MarketingCampaign::SEQUENCE_INTERVAL_UNITS)
            ? $unit
            : 'days';
    }
}
