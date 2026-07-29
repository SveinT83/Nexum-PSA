<?php

namespace App\Modules\Integration\Support;

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
        return new self(
            successful: false,
            content: null,
            usage: $usage,
            actualModel: $actualModel,
            providerRequestId: $providerRequestId,
            finishReason: $finishReason,
            httpStatus: $httpStatus,
            errorCategory: $errorCategory,
            errorCode: $errorCode,
        );
    }
}
