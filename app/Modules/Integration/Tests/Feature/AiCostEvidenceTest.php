<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiModelRateCard;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Services\AiCostCalculator;
use App\Modules\Integration\Services\AiUsageRecorder;
use App\Modules\Integration\Support\AiExecutionContext;
use App\Modules\Integration\Support\AiModelResult;
use App\Modules\Integration\Support\AiModelUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiCostEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): AiAgent
    {
        $provider = AiProvider::create(['name' => 'Cost fixture', 'provider_key' => 'ollama',
            'base_url' => 'https://cost.example.test', 'default_model' => 'cost-model', 'status' => 'active']);

        return AiAgent::create(['name' => 'Cost fixture', 'slug' => 'cost-fixture-'.Str::random(8),
            'ai_provider_id' => $provider->id, 'model' => 'cost-model', 'instructions' => 'Cost fixture.', 'is_active' => true]);
    }

    private function rateCard(AiAgent $agent, string $currency = 'USD'): AiModelRateCard
    {
        $card = AiModelRateCard::create(['name' => 'Cost fixture', 'ai_provider_id' => $agent->ai_provider_id,
            'model_pattern' => 'cost-model', 'currency' => $currency, 'effective_from' => now()->subDay(), 'is_active' => true]);
        $card->rates()->create(['metric' => 'input_token', 'rate' => '1.000000000000', 'unit_quantity' => 1000]);

        return $card;
    }

    private function record(AiAgent $agent, AiModelUsage $usage, bool $successful = true, ?string $executionId = null, int $attempt = 1)
    {
        $context = new AiExecutionContext($executionId ?? (string) Str::uuid(), 'integration.cost_test', 'fixture', 'integration');
        $result = $successful
            ? AiModelResult::success('Fixture', $usage, 'cost-model', null, 'stop', 200)
            : AiModelResult::failure($usage, null, null, null, 503, 'provider_http_error');

        return app(AiUsageRecorder::class)->record($agent, $context, $attempt, 'fixture', 'cost-model', $result, now(), now(), 1);
    }

    #[Test]
    public function reported_zero_wins_over_estimate_and_cost_fields_are_actually_persisted(): void
    {
        $agent = $this->agent();
        $this->rateCard($agent);
        $event = $this->record($agent, new AiModelUsage(inputTokens: 100, outputTokens: 0, totalTokens: 100,
            providerReportedCost: '0', costCurrency: 'USD'));
        $this->assertSame('0.000000000000', $event->fresh()->effective_cost);
        $this->assertSame('provider_reported', $event->fresh()->cost_source);
        $this->assertSame('0.100000000000', $event->fresh()->calculated_cost);
        $this->assertIsArray($event->fresh()->pricing_snapshot);
    }

    #[Test]
    public function missing_rate_card_is_unknown_instead_of_a_free_call(): void
    {
        $agent = $this->agent();
        $calculated = app(AiCostCalculator::class)->calculate(AiModelUsage::unavailable(), $agent->provider, 'cost-model');
        $this->assertNull($calculated['total_cost']);
        $this->assertSame('unknown', $calculated['source']);
    }

    #[Test]
    public function failed_attempts_keep_reported_cost_and_unknown_currency(): void
    {
        $event = $this->record($this->agent(), new AiModelUsage(providerReportedCost: '0.000000000001'), false)->fresh();
        $this->assertSame('0.000000000001', $event->effective_cost);
        $this->assertSame('provider_reported', $event->cost_source);
        $this->assertNull($event->cost_currency);
        $this->assertSame('unknown_currency', \App\Modules\Integration\Support\AiCostEvidence::forEvent($event)['state']);
    }

    #[Test]
    public function estimates_require_known_units_and_supported_prices(): void
    {
        $agent = $this->agent();
        $card = $this->rateCard($agent, 'NOK');
        $calc = app(AiCostCalculator::class);
        $this->assertNull($calc->calculate(AiModelUsage::unavailable(), $agent->provider, 'cost-model')['total_cost']);
        $this->assertNull($calc->calculate(new AiModelUsage(inputTokens: 100, outputTokens: 1), $agent->provider, 'cost-model')['total_cost']);
        $known = $calc->calculate(new AiModelUsage(inputTokens: 100, outputTokens: 0), $agent->provider, 'cost-model');
        $this->assertSame('0.100000000000', $known['total_cost']);
        $this->assertSame('NOK', $known['currency']);
        $card->rates()->create(['metric' => 'unknown_metric', 'rate' => '1', 'unit_quantity' => 1]);
        $this->assertNull($calc->calculate(new AiModelUsage(inputTokens: 100, outputTokens: 0), $agent->provider, 'cost-model')['total_cost']);
    }

    #[Test]
    public function cached_input_is_not_billed_twice_and_non_token_work_remains_unknown(): void
    {
        $agent = $this->agent();
        $card = $this->rateCard($agent);
        $card->rates()->create(['metric' => 'cached_input_token', 'rate' => '0.1', 'unit_quantity' => 1000]);
        $calc = app(AiCostCalculator::class);
        $known = $calc->calculate(new AiModelUsage(inputTokens: 200, outputTokens: 0, cachedInputTokens: 50), $agent->provider, 'cost-model');
        $this->assertSame('0.155000000000', $known['total_cost']);
        $this->assertSame(150, $known['pricing_snapshot']['metrics']['input_token']['units']);
        $unknown = $calc->calculate(new AiModelUsage(inputTokens: 200, outputTokens: 0, cachedInputTokens: 50, nonTokenUsage: ['web_search_requests' => 1]), $agent->provider, 'cost-model');
        $this->assertNull($unknown['total_cost']);
    }

    #[Test]
    public function fallback_and_currency_summaries_keep_each_attempt_and_historical_uncertainty(): void
    {
        $agent = $this->agent();
        $execution = (string) Str::uuid();
        $this->record($agent, new AiModelUsage(totalTokens: 1, providerReportedCost: '0.1', costCurrency: 'USD'), false, $execution, 1);
        $this->record($agent, new AiModelUsage(totalTokens: 2, providerReportedCost: '0.2', costCurrency: 'USD'), true, $execution, 2);
        $legacy = $this->record($agent, AiModelUsage::unavailable());
        $legacy->update(['effective_cost' => '0', 'cost_source' => 'none']);
        $this->record($agent, new AiModelUsage(totalTokens: 0, providerReportedCost: '2', costCurrency: 'NOK'));
        $read = app(\App\Modules\Integration\Services\AiTelemetryRead::class);
        $summary = $read->summary(\App\Modules\Integration\Models\AiModelUsageEvent::query());
        $this->assertSame(4, $summary['attempts']);
        $this->assertSame(3, $summary['executions']);
        $this->assertSame('0.300000000000', $summary['currencies']['USD']['known_subtotal']);
        $this->assertSame('2.000000000000', $summary['currencies']['NOK']['known_subtotal']);
        $this->assertSame(1, $summary['unknown_cost_attempts']);
        $this->assertSame(1, $summary['unknown_token_attempts']);
        $this->assertFalse($summary['complete']);
        $this->assertNull(\App\Modules\Integration\Support\AiCostEvidence::forEvent($legacy->fresh())['amount']);
    }

    #[Test]
    public function mixed_reported_and_estimated_sources_remain_explicit(): void
    {
        $agent = $this->agent();
        $this->rateCard($agent);
        $this->record($agent, new AiModelUsage(inputTokens: 100, outputTokens: 0, totalTokens: 100));
        $this->record($agent, new AiModelUsage(providerReportedCost: '0.2', costCurrency: 'USD'));
        $summary = app(\App\Modules\Integration\Services\AiTelemetryRead::class)
            ->summary(\App\Modules\Integration\Models\AiModelUsageEvent::query());
        $this->assertSame('mixed', $summary['source_state']);
        $this->assertSame('known', $summary['cost_state']);
        $this->assertSame('0.300000000000', $summary['currencies']['USD']['known_subtotal']);
        $this->assertSame('0.100000000000', $summary['currencies']['USD']['estimated_subtotal']);
        $this->assertSame('0.200000000000', $summary['currencies']['USD']['provider_reported_subtotal']);
    }
}
