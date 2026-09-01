<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiModelRateCard;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Support\AiModelUsage;

class AiCostCalculator
{
    /**
     * Calculate cost for a given usage, provider and model.
     * Returns an array with 'total_cost', 'source' and 'pricing_snapshot'.
     */
    public function calculate(AiModelUsage $usage, AiProvider $provider, ?string $modelName): array
    {
        $rateCard = $this->findRateCard($provider, $modelName);

        if (! $rateCard) {
            return [
                'total_cost' => 0.0,
                'source' => 'none',
                'pricing_snapshot' => null,
            ];
        }

        $totalCost = 0.0;
        $snapshot = [];

        foreach ($rateCard->rates as $rate) {
            $units = match ($rate->metric) {
                'input_token' => $usage->inputTokens,
                'output_token' => $usage->outputTokens,
                'cached_input_token' => $usage->cachedInputTokens,
                default => 0,
            };

            if ($units > 0) {
                $cost = ($units / $rate->unit_quantity) * (float) $rate->rate;
                $totalCost += $cost;
                $snapshot[$rate->metric] = [
                    'units' => $units,
                    'rate' => (float) $rate->rate,
                    'unit_quantity' => $rate->unit_quantity,
                    'cost' => $cost,
                ];
            }
        }

        return [
            'total_cost' => $totalCost,
            'source' => 'rate_card:'.$rateCard->id,
            'pricing_snapshot' => $snapshot,
        ];
    }

    private function findRateCard(AiProvider $provider, ?string $modelName): ?AiModelRateCard
    {
        if (! $modelName) {
            return null;
        }

        // Try exact match first
        $card = AiModelRateCard::query()
            ->with('rates')
            ->active()
            ->where('ai_provider_id', $provider->id)
            ->where('model_pattern', $modelName)
            ->first();

        if ($card) {
            return $card;
        }

        // Try pattern match (very basic wildcard for now)
        $cards = AiModelRateCard::query()
            ->with('rates')
            ->active()
            ->where('ai_provider_id', $provider->id)
            ->get();

        foreach ($cards as $c) {
            $pattern = str_replace('*', '.*', $c->model_pattern);
            if (preg_match('/^'.$pattern.'$/i', $modelName)) {
                return $c;
            }
        }

        // Try global provider default (pattern '*')
        return AiModelRateCard::query()
            ->with('rates')
            ->active()
            ->where('ai_provider_id', $provider->id)
            ->where('model_pattern', '*')
            ->first();
    }
}
