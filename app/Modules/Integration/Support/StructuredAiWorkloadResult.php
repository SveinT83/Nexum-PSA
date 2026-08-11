<?php

namespace App\Modules\Integration\Support;

final class StructuredAiWorkloadResult
{
    private function __construct(
        public readonly StructuredAiWorkloadStatus $status,
        public readonly StructuredAiExecutionMetadata $metadata,
        public readonly ?array $data = null,
        public readonly ?string $reasonCode = null,
    ) {}

    public static function success(
        array $data,
        StructuredAiExecutionMetadata $metadata,
    ): self {
        return new self(
            status: StructuredAiWorkloadStatus::Success,
            metadata: $metadata,
            data: $data,
        );
    }

    public static function denied(
        string $reasonCode,
        StructuredAiExecutionMetadata $metadata,
    ): self {
        return new self(
            status: StructuredAiWorkloadStatus::Denied,
            metadata: $metadata,
            reasonCode: $reasonCode,
        );
    }

    public static function unavailable(
        string $reasonCode,
        StructuredAiExecutionMetadata $metadata,
    ): self {
        return new self(
            status: StructuredAiWorkloadStatus::Unavailable,
            metadata: $metadata,
            reasonCode: $reasonCode,
        );
    }

    public static function invalid(
        string $reasonCode,
        StructuredAiExecutionMetadata $metadata,
    ): self {
        return new self(
            status: StructuredAiWorkloadStatus::Invalid,
            metadata: $metadata,
            reasonCode: $reasonCode,
        );
    }

    public function successful(): bool
    {
        return $this->status === StructuredAiWorkloadStatus::Success;
    }
}
