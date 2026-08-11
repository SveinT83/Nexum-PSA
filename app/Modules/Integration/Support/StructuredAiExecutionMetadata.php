<?php

namespace App\Modules\Integration\Support;

final class StructuredAiExecutionMetadata
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $requestSchemaVersion,
        public readonly string $responseSchemaVersion,
        public readonly ?int $workloadId = null,
        public readonly ?string $workloadSlug = null,
        public readonly ?int $agentId = null,
        public readonly ?string $providerId = null,
        public readonly ?string $requestedModel = null,
        public readonly ?string $actualModel = null,
        public readonly ?string $providerRequestId = null,
        public readonly ?string $processingMode = null,
        public readonly ?string $dataProfile = null,
        public readonly ?int $policyRevision = null,
        public readonly ?int $accessEventId = null,
        public readonly ?string $providerReportedCost = null,
        public readonly ?string $costCurrency = null,
    ) {}

    public function withAccessEvent(int $accessEventId): self
    {
        return new self(
            executionId: $this->executionId, requestSchemaVersion: $this->requestSchemaVersion,
            responseSchemaVersion: $this->responseSchemaVersion, workloadId: $this->workloadId,
            workloadSlug: $this->workloadSlug, agentId: $this->agentId, providerId: $this->providerId,
            requestedModel: $this->requestedModel, actualModel: $this->actualModel,
            providerRequestId: $this->providerRequestId, processingMode: $this->processingMode,
            dataProfile: $this->dataProfile, policyRevision: $this->policyRevision,
            accessEventId: $accessEventId, providerReportedCost: $this->providerReportedCost,
            costCurrency: $this->costCurrency,
        );
    }

    public function withModelResult(AiModelResult $result): self
    {
        return new self(
            executionId: $this->executionId,
            requestSchemaVersion: $this->requestSchemaVersion,
            responseSchemaVersion: $this->responseSchemaVersion,
            workloadId: $this->workloadId,
            workloadSlug: $this->workloadSlug,
            agentId: $this->agentId,
            providerId: $this->providerId,
            requestedModel: $this->requestedModel,
            actualModel: $result->actualModel,
            providerRequestId: $result->providerRequestId,
            processingMode: $this->processingMode,
            dataProfile: $this->dataProfile,
            policyRevision: $this->policyRevision,
            accessEventId: $this->accessEventId,
            providerReportedCost: $result->usage->providerReportedCost,
            costCurrency: $result->usage->costCurrency,
        );
    }
}
