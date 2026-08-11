<?php

namespace App\Modules\Integration\Tests\Unit;

use App\Modules\Integration\Support\AiModelUsage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiModelUsageTest extends TestCase
{
    #[Test]
    public function responses_usage_preserves_explicit_zero_and_allowlisted_details(): void
    {
        $usage = AiModelUsage::fromOpenAiCompatible([
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 0,
                'total_tokens' => 10,
                'input_tokens_details' => [
                    'cached_tokens' => 4,
                    'cache_write_tokens' => 2,
                    'audio_tokens' => 0,
                ],
                'output_tokens_details' => [
                    'reasoning_tokens' => 0,
                    'audio_tokens' => 0,
                ],
            ],
            'output' => [
                ['type' => 'web_search_call'],
                ['type' => 'message', 'content' => 'not retained in usage'],
            ],
        ]);

        $this->assertSame(10, $usage->inputTokens);
        $this->assertSame(0, $usage->outputTokens);
        $this->assertSame(10, $usage->totalTokens);
        $this->assertSame(4, $usage->cachedInputTokens);
        $this->assertSame(2, $usage->cacheWriteTokens);
        $this->assertSame(0, $usage->reasoningTokens);
        $this->assertSame(0, $usage->audioInputTokens);
        $this->assertSame(0, $usage->audioOutputTokens);
        $this->assertSame('provider_reported', $usage->source);
        $this->assertSame(1, $usage->nonTokenUsage['web_search_calls']);
        $this->assertArrayNotHasKey('content', $usage->providerUsage);
    }

    #[Test]
    public function missing_usage_remains_unavailable_instead_of_becoming_zero(): void
    {
        $usage = AiModelUsage::fromOpenAiCompatible([
            'model' => 'gpt-example',
            'choices' => [
                ['message' => ['content' => 'This content is ignored by normalization.']],
            ],
        ]);

        $this->assertNull($usage->inputTokens);
        $this->assertNull($usage->outputTokens);
        $this->assertNull($usage->totalTokens);
        $this->assertNull($usage->cachedInputTokens);
        $this->assertNull($usage->providerReportedCost);
        $this->assertSame('unavailable', $usage->source);
        $this->assertSame([], $usage->providerUsage);
    }

    #[Test]
    public function ollama_usage_derives_total_only_from_reported_counters(): void
    {
        $usage = AiModelUsage::fromOllama([
            'prompt_eval_count' => 5,
            'eval_count' => 3,
            'total_duration' => 99,
        ]);

        $this->assertSame(5, $usage->inputTokens);
        $this->assertSame(3, $usage->outputTokens);
        $this->assertSame(8, $usage->totalTokens);
        $this->assertSame(99, $usage->providerTiming['total_duration_ns']);
        $this->assertSame('provider_reported', $usage->source);
    }
}
