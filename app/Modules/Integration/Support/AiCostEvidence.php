<?php

namespace App\Modules\Integration\Support;

use App\Modules\Integration\Models\AiModelUsageEvent;

/** Read evidence without backfilling or assigning certainty to old null/none rows. */
final class AiCostEvidence
{
    public static function forEvent(AiModelUsageEvent $event): array
    {
        $reported = AiCostDecimal::stored($event->provider_reported_cost);
        $estimated = AiCostDecimal::stored($event->calculated_cost);
        $source = 'unknown';
        $amount = null;
        $currency = AiCostDecimal::currency($event->cost_currency);
        if ($reported !== null) {
            $source = 'provider_reported';
            $amount = $reported;
        } elseif ($event->cost_source === 'estimated' || str_starts_with($event->cost_source ?? '', 'rate_card:')) {
            $amount = AiCostDecimal::stored($event->effective_cost);
            $source = $amount === null ? 'unknown' : 'estimated';
        }

        return [
            'amount' => $amount, 'currency' => $amount === null ? null : $currency,
            'source' => $source,
            'state' => $amount === null ? 'unknown' : ($currency === null ? 'unknown_currency' : 'known'),
            'provider_reported_amount' => $reported,
            'provider_reported_currency' => $reported === null ? null : $currency,
            'estimated_amount' => $estimated,
            'estimated_currency' => $estimated === null ? null : AiCostDecimal::currency(data_get($event->pricing_snapshot, 'currency')),
        ];
    }

    public static function display(?string $amount, ?string $currency): string
    {
        return $amount === null ? 'Unknown' : $amount.' '.($currency ?? '(currency unknown)');
    }
}
