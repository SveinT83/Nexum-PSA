<?php

namespace App\Modules\Integration\Support;

use Illuminate\Http\Client\Response;

final class AiModelResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly ?string $content,
        public readonly AiModelUsage $usage,
        public readonly ?string $actualModel = null,
        public readonly ?string $providerRequestId = null,
        public readonly ?string $finishReason = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorCategory = null,
        public readonly ?string $errorCode = null,
    ) {}

    public static function fromOpenAiCompatible(Response $response, string $endpointKind, ?string $content = null): self
    {
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $usage = AiModelUsage::fromOpenAiCompatible($payload);
        $actualModel = self::stringValue(data_get($payload, 'model'));
        $providerRequestId = self::providerRequestId($response, $payload);
        $finishReason = self::stringValue(
            data_get($payload, 'choices.0.finish_reason')
                ?? data_get($payload, 'incomplete_details.reason')
                ?? data_get($payload, 'status')
        );

        if (! $response->successful()) {
            return self::failure(
                usage: $usage,
                actualModel: $actualModel,
                providerRequestId: $providerRequestId,
                finishReason: $finishReason,
                httpStatus: $response->status(),
                errorCategory: 'provider_http_error',
                errorCode: self::stringValue(data_get($payload, 'error.code')) ?: 'http_'.$response->status(),
            );
        }

        $content = $content ?? match ($endpointKind) {
            'chat_completions' => data_get($payload, 'choices.0.message.content'),
            'completions' => data_get($payload, 'choices.0.text'),
            default => null,
        };

        if (! filled($content)) {
            return self::failure(
                usage: $usage,
                actualModel: $actualModel,
                providerRequestId: $providerRequestId,
                finishReason: $finishReason,
                httpStatus: $response->status(),
                errorCategory: 'empty_response',
            );
        }

        return self::success(
            content: trim((string) $content),
            usage: $usage,
            actualModel: $actualModel,
            providerRequestId: $providerRequestId,
            finishReason: $finishReason,
            httpStatus: $response->status(),
        );
    }

    public static function fromOllama(Response $response): self
    {
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $usage = AiModelUsage::fromOllama($payload);
        $actualModel = self::stringValue(data_get($payload, 'model'));
        $finishReason = self::stringValue(data_get($payload, 'done_reason'));

        if (! $response->successful()) {
            return self::failure(
                usage: $usage,
                actualModel: $actualModel,
                providerRequestId: self::providerRequestId($response, $payload),
                finishReason: $finishReason,
                httpStatus: $response->status(),
                errorCategory: 'provider_http_error',
                errorCode: self::stringValue(data_get($payload, 'error.code')) ?: 'http_'.$response->status(),
            );
        }

        $content = data_get($payload, 'message.content');

        if (! filled($content)) {
            return self::failure(
                usage: $usage,
                actualModel: $actualModel,
                providerRequestId: self::providerRequestId($response, $payload),
                finishReason: $finishReason,
                httpStatus: $response->status(),
                errorCategory: 'empty_response',
            );
        }

        return self::success(
            content: trim((string) $content),
            usage: $usage,
            actualModel: $actualModel,
            providerRequestId: self::providerRequestId($response, $payload),
            finishReason: $finishReason,
            httpStatus: $response->status(),
        );
    }

    private static function providerRequestId(Response $response, array $payload): ?string
    {
        return self::stringValue(data_get($payload, 'id'))
            ?: self::stringValue($response->header('x-request-id'))
            ?: self::stringValue($response->header('openai-request-id'));
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    public static function success(
        string $content,
        AiModelUsage $usage,
        ?string $actualModel,
        ?string $providerRequestId,
        ?string $finishReason,
        ?int $httpStatus,
    ): self {
        return new self(
            successful: true,
            content: $content,
            usage: $usage,
            actualModel: $actualModel,
            providerRequestId: $providerRequestId,
            finishReason: $finishReason,
            httpStatus: $httpStatus,
        );
    }

    public static function failure(
        AiModelUsage $usage,
        ?string $actualModel,
        ?string $providerRequestId,
        ?string $finishReason,
        ?int $httpStatus,
        string $errorCategory,
        ?string $errorCode = null,
    ): self {
        // Error responses are not a trusted identity source. Local execution/attempt IDs
        // remain the correlation authority; arbitrary error codes are never diagnostics.
        $httpStatus = $httpStatus !== null && $httpStatus >= 100 && $httpStatus <= 599 ? $httpStatus : null;
        $safeCodes = ['service_unavailable', 'rate_limit_exceeded', 'invalid_api_key',
            'insufficient_quota', 'model_not_found', 'context_length_exceeded',
            'transport_error', 'response_parse_error'];
        $safeCategories = ['provider_http_error', 'empty_response', 'transport_error', 'response_parse_error'];

        return new self(
            successful: false,
            content: null,
            usage: $usage,
            actualModel: null,
            providerRequestId: null,
            finishReason: null,
            httpStatus: $httpStatus,
            errorCategory: in_array($errorCategory, $safeCategories, true) ? $errorCategory : 'provider_http_error',
            errorCode: in_array($errorCode, $safeCodes, true) ? $errorCode : ($httpStatus ? 'http_'.$httpStatus : null),
        );
    }
}
