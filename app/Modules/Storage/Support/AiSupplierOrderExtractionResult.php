<?php

namespace App\Modules\Storage\Support;

final class AiSupplierOrderExtractionResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?array $document,
        public readonly ?string $reasonCode,
        public readonly ?string $executionId,
        public readonly array $metadata,
        public readonly ?array $profileCandidateDefinition = null,
    ) {}

    public function successful(): bool
    {
        return $this->status === 'success' && $this->document !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason_code' => $this->reasonCode,
            'execution_id' => $this->executionId,
            'metadata' => $this->metadata,
            'profile_candidate_present' => $this->profileCandidateDefinition !== null,
            'profile_candidate_checksum' => $this->profileCandidateDefinition ? StableJson::checksum($this->profileCandidateDefinition) : null,
        ];
    }
}
