<?php

namespace App\Modules\Integration\Support;

final class AiModelUsage
{
    public function __construct(
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $totalTokens = null,
        public readonly ?int $cachedInputTokens = null,
        public readonly ?int $cacheWriteTokens = null,
        public readonly ?int $reasoningTokens = null,
        public readonly ?int $audioInputTokens = null,
        public readonly ?int $audioOutputTokens = null,
        public readonly string $source = 'unavailable',
        public readonly ?string $providerReportedCost = null,
        public readonly ?string $costCurrency = null,
        public readonly array $providerTiming = [],
        public readonly array $nonTokenUsage = [],
        public readonly array $providerUsage = [],
    ) {}

    public static function unavailable(): self
    {
        return new self;
    }

    /**
     * Normalize the common OpenAI-compatible and OpenRouter usage contracts.
     */
    public static function fromOpenAiCompatible(array $payload): self
    {
        $usage = data_get($payload, 'usage');
        $usage = is_array($usage) ? $usage : [];

        $inputTokens = self::firstInteger($usage, ['input_tokens', 'prompt_tokens']);
        $outputTokens = self::firstInteger($usage, ['output_tokens', 'completion_tokens']);
        $totalTokens = self::firstInteger($usage, ['total_tokens']);

        if ($totalTokens === null && $inputTokens !== null && $outputTokens !== null) {
            $totalTokens = $inputTokens + $outputTokens;
        }

        $cachedInputTokens = self::firstInteger($usage, [
            'input_tokens_details.cached_tokens',
            'prompt_tokens_details.cached_tokens',
        ]);
        $cacheWriteTokens = self::firstInteger($usage, [
            'input_tokens_details.cache_write_tokens',
            'prompt_tokens_details.cache_write_tokens',
        ]);
        $reasoningTokens = self::firstInteger($usage, [
            'output_tokens_details.reasoning_tokens',
            'completion_tokens_details.reasoning_tokens',
        ]);
        $audioInputTokens = self::firstInteger($usage, [
            'input_tokens_details.audio_tokens',
            'prompt_tokens_details.audio_tokens',
        ]);
        $audioOutputTokens = self::firstInteger($usage, [
            'output_tokens_details.audio_tokens',
            'completion_tokens_details.audio_tokens',
        ]);
        $providerReportedCost = self::firstDecimal($usage, ['cost', 'total_cost']);
        $costCurrency = self::currency(self::firstValue($usage, ['currency', 'cost_currency']));

        $nonTokenUsage = self::nonTokenUsage($payload, $usage);
        $providerUsage = array_filter([
            'input_token_details' => self::withoutNulls([
                'cached_tokens' => $cachedInputTokens,
                'cache_write_tokens' => $cacheWriteTokens,
                'audio_tokens' => $audioInputTokens,
            ]),
            'output_token_details' => self::withoutNulls([
                'reasoning_tokens' => $reasoningTokens,
                'audio_tokens' => $audioOutputTokens,
            ]),
            'cost_details' => self::costDetails($usage),
        ], fn (array $values): bool => $values !== []);

        $hasReportedUsage = $usage !== [] || $nonTokenUsage !== [];

        return new self(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            cachedInputTokens: $cachedInputTokens,
            cacheWriteTokens: $cacheWriteTokens,
            reasoningTokens: $reasoningTokens,
            audioInputTokens: $audioInputTokens,
            audioOutputTokens: $audioOutputTokens,
            source: $hasReportedUsage ? 'provider_reported' : 'unavailable',
            providerReportedCost: $providerReportedCost,
            costCurrency: $costCurrency,
            nonTokenUsage: $nonTokenUsage,
            providerUsage: $providerUsage,
        );
    }

    /**
     * Normalize Ollama's token counts and nanosecond timing metrics.
     */
    public static function fromOllama(array $payload): self
    {
        $inputTokens = self::firstInteger($payload, ['prompt_eval_count']);
        $outputTokens = self::firstInteger($payload, ['eval_count']);
        $totalTokens = $inputTokens !== null && $outputTokens !== null
            ? $inputTokens + $outputTokens
            : null;
        $providerTiming = self::withoutNulls([
            'total_duration_ns' => self::firstInteger($payload, ['total_duration']),
            'load_duration_ns' => self::firstInteger($payload, ['load_duration']),
            'prompt_eval_duration_ns' => self::firstInteger($payload, ['prompt_eval_duration']),
            'eval_duration_ns' => self::firstInteger($payload, ['eval_duration']),
        ]);
        $hasReportedUsage = $inputTokens !== null
            || $outputTokens !== null
            || $providerTiming !== [];

        return new self(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            source: $hasReportedUsage ? 'provider_reported' : 'unavailable',
            providerTiming: $providerTiming,
        );
    }

    private static function nonTokenUsage(array $payload, array $usage): array
    {
        $values = self::withoutNulls([
            'web_search_requests' => self::firstInteger($usage, [
                'web_search_requests',
                'web_search_requests_count',
            ]),
            'image_requests' => self::firstInteger($usage, [
                'image_requests',
                'image_requests_count',
            ]),
        ]);
        $allowedOutputTypes = [
            'web_search_call',
            'file_search_call',
            'image_generation_call',
            'computer_call',
        ];
        $outputTypeCounts = [];

        foreach ((array) data_get($payload, 'output', []) as $outputItem) {
            $type = is_array($outputItem) ? ($outputItem['type'] ?? null) : null;

            if (is_string($type) && in_array($type, $allowedOutputTypes, true)) {
                $key = $type.'s';
                $outputTypeCounts[$key] = ($outputTypeCounts[$key] ?? 0) + 1;
            }
        }

        return array_merge($values, $outputTypeCounts);
    }

    private static function costDetails(array $usage): array
    {
        $details = data_get($usage, 'cost_details');
        $details = is_array($details) ? $details : [];

        return self::withoutNulls([
            'upstream_inference_cost' => self::firstDecimal($details, ['upstream_inference_cost']),
            'cache_discount' => self::firstDecimal($details, ['cache_discount']),
        ]);
    }

    private static function firstInteger(array $source, array $paths): ?int
    {
        $value = self::firstValue($source, $paths);

        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (int) $value;
    }

    private static function firstDecimal(array $source, array $paths): ?string
    {
        $value = self::firstValue($source, $paths);

        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        if (is_int($value) || (is_string($value) && ! str_contains(strtolower($value), 'e'))) {
            return trim((string) $value);
        }

        return rtrim(rtrim(number_format((float) $value, 12, '.', ''), '0'), '.');
    }

    private static function firstValue(array $source, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($source, $path);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function currency(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : null;
    }

    private static function withoutNulls(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null);
    }
}
