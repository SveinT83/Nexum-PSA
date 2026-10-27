<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiModelRateCard;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Support\AiCostDecimal;
use App\Modules\Integration\Support\AiModelUsage;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class AiCostCalculator
{
    /** Missing price or required usage evidence remains unknown; estimates never imply provider charges. */
    public function calculate(AiModelUsage $usage, AiProvider $provider, ?string $modelName): array
    {
        $unknown = ['total_cost' => null, 'currency' => null, 'source' => 'unknown', 'pricing_snapshot' => null];
        $card = $this->findRateCard($provider, $modelName);
        if (! $card || ! AiCostDecimal::currency($card->currency) || $card->rates->isEmpty()
            || $usage->inputTokens === null || $usage->outputTokens === null
            || collect($usage->nonTokenUsage)->contains(fn ($units) => $units > 0)
            || ($usage->audioInputTokens ?? 0) > 0 || ($usage->audioOutputTokens ?? 0) > 0
            || ($usage->cacheWriteTokens ?? 0) > 0) {
            return $unknown;
        }
        $rates = $card->rates->keyBy('metric');
        if ($rates->keys()->diff(['input_token', 'output_token', 'cached_input_token'])->isNotEmpty()
            || ($usage->inputTokens > 0 && ! $rates->has('input_token'))
            || ($usage->outputTokens > 0 && ! $rates->has('output_token'))) {
            return $unknown;
        }
        $counts = ['input_token' => $usage->inputTokens, 'output_token' => $usage->outputTokens];
        // Cached input is a subset of input, charged once at its explicit cache rate.
        if ($rates->has('cached_input_token')) {
            if ($usage->cachedInputTokens === null || $usage->cachedInputTokens > $usage->inputTokens) {
                return $unknown;
            }
            $counts['input_token'] -= $usage->cachedInputTokens;
            $counts['cached_input_token'] = $usage->cachedInputTokens;
        }
        $total = BigDecimal::zero();
        $snapshot = ['rate_card_id' => $card->id, 'currency' => $card->currency, 'metrics' => []];
        foreach ($rates as $metric => $rate) {
            $price = AiCostDecimal::parse($rate->rate);
            if ($price === null || $rate->unit_quantity < 1) {
                return $unknown;
            }
            $amount = $price->multipliedBy($counts[$metric] ?? 0)
                ->dividedBy($rate->unit_quantity, 24, RoundingMode::HalfUp);
            $total = $total->plus($amount);
            $snapshot['metrics'][$metric] = [
                'units' => $counts[$metric] ?? 0, 'rate' => (string) $price,
                'unit_quantity' => $rate->unit_quantity, 'cost' => (string) $amount,
            ];
        }
        $stored = AiCostDecimal::stored((string) $total);

        return $stored === null ? $unknown : [
            'total_cost' => $stored, 'currency' => $card->currency,
            'source' => 'estimated', 'pricing_snapshot' => $snapshot,
        ];
    }

    private function findRateCard(AiProvider $provider, ?string $modelName): ?AiModelRateCard
    {
        if (! $modelName) {
            return null;
        }
        $cards = AiModelRateCard::query()->with('rates')->active()
            ->where('ai_provider_id', $provider->id)->orderByDesc('effective_from')->orderBy('id')->get();
        $exact = $cards->firstWhere('model_pattern', $modelName);
        if ($exact) {
            return $exact;
        }
        foreach ($cards->sortByDesc(fn ($card) => strlen(str_replace('*', '', $card->model_pattern))) as $card) {
            $pattern = str_replace('\*', '.*', preg_quote($card->model_pattern, '/'));
            if (preg_match('/^'.$pattern.'$/i', $modelName)) {
                return $card;
            }
        }

        return null;
    }
}
