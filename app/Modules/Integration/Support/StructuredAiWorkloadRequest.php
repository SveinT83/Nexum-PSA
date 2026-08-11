<?php

namespace App\Modules\Integration\Support;

use InvalidArgumentException;

final class StructuredAiWorkloadRequest
{
    public readonly string $workloadSlug;

    public readonly string $requestSchemaVersion;

    public readonly string $responseSchemaVersion;

    public readonly string $operation;

    public readonly array $input;

    public readonly array $allowedInputFields;

    public readonly array $responseDataSchema;

    public readonly AiExecutionContext $executionContext;

    public readonly array $configuredIdentifiers;

    public readonly int $timeoutSeconds;

    public readonly int $maxOutputTokens;

    public readonly ?string $reasoningEffort;

    public readonly ?string $maxProviderReportedCost;

    public readonly ?string $costCurrency;

    public function __construct(
        string $workloadSlug,
        string $requestSchemaVersion,
        string $responseSchemaVersion,
        string $operation,
        array $input,
        array $allowedInputFields,
        array $responseDataSchema,
        AiExecutionContext $executionContext,
        array $configuredIdentifiers = [],
        int $timeoutSeconds = 120,
        int $maxOutputTokens = 2000,
        ?string $reasoningEffort = null,
        int|float|string|null $maxProviderReportedCost = null,
        ?string $costCurrency = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,118}[a-z0-9]$/', $workloadSlug) !== 1) {
            throw new InvalidArgumentException('Invalid internal AI workload slug.');
        }
        foreach ([$requestSchemaVersion, $responseSchemaVersion] as $schemaVersion) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{1,180}\.v[1-9][0-9]*$/', $schemaVersion) !== 1) {
                throw new InvalidArgumentException('Invalid structured AI schema version.');
            }
        }
        if (preg_match('/^[a-z][a-z0-9_]{1,119}$/', $operation) !== 1) {
            throw new InvalidArgumentException('Invalid structured AI operation.');
        }
        if ($input === [] || $allowedInputFields === [] || $responseDataSchema === []) {
            throw new InvalidArgumentException('Structured AI input, allowlist, and response schema are required.');
        }
        foreach ($allowedInputFields as $field) {
            if (! is_string($field)
                || strlen($field) > 180
                || preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*(?:\.\*)?$/', $field) !== 1) {
                throw new InvalidArgumentException('Invalid structured AI input allowlist.');
            }
        }
        if (count($allowedInputFields) > 150 || count(array_unique($allowedInputFields)) !== count($allowedInputFields)) {
            throw new InvalidArgumentException('Structured AI input allowlist is too broad or contains duplicates.');
        }
        if (count($configuredIdentifiers) > 100
            || collect($configuredIdentifiers)->contains(
                fn (mixed $identifier): bool => ! is_string($identifier) || mb_strlen($identifier) > 255,
            )) {
            throw new InvalidArgumentException('Invalid structured AI identifier list.');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 180) {
            throw new InvalidArgumentException('Structured AI timeout must be between 1 and 180 seconds.');
        }
        if ($maxOutputTokens < 1 || $maxOutputTokens > 12000) {
            throw new InvalidArgumentException('Structured AI output limit must be between 1 and 12000 tokens.');
        }
        if ($reasoningEffort !== null
            && ! in_array($reasoningEffort, ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], true)) {
            throw new InvalidArgumentException('Invalid structured AI reasoning effort.');
        }
        if ($maxProviderReportedCost !== null
            && (! is_numeric($maxProviderReportedCost) || (float) $maxProviderReportedCost < 0)) {
            throw new InvalidArgumentException('Structured AI cost limit must be a non-negative decimal.');
        }
        $costCurrency = is_string($costCurrency) ? strtoupper(trim($costCurrency)) : null;
        if ($maxProviderReportedCost !== null && preg_match('/^[A-Z]{3}$/', (string) $costCurrency) !== 1) {
            throw new InvalidArgumentException('Structured AI cost limits require an ISO currency.');
        }
        if ($maxProviderReportedCost === null && $costCurrency !== null) {
            throw new InvalidArgumentException('Structured AI cost currency requires a cost limit.');
        }

        $this->workloadSlug = $workloadSlug;
        $this->requestSchemaVersion = $requestSchemaVersion;
        $this->responseSchemaVersion = $responseSchemaVersion;
        $this->operation = $operation;
        $this->input = $input;
        $this->allowedInputFields = array_values($allowedInputFields);
        $this->responseDataSchema = $responseDataSchema;
        $this->executionContext = $executionContext;
        $this->configuredIdentifiers = array_values($configuredIdentifiers);
        $this->timeoutSeconds = $timeoutSeconds;
        $this->maxOutputTokens = $maxOutputTokens;
        $this->reasoningEffort = $reasoningEffort;
        $this->maxProviderReportedCost = $maxProviderReportedCost === null
            ? null
            : rtrim(rtrim(number_format((float) $maxProviderReportedCost, 12, '.', ''), '0'), '.');
        $this->costCurrency = $costCurrency;
    }

    public function inputEnvelope(): array
    {
        return [
            'schema_version' => $this->requestSchemaVersion,
            'operation' => $this->operation,
            'input' => $this->input,
        ];
    }

    public function allowedEnvelopeFields(): array
    {
        return [
            'schema_version',
            'operation',
            ...array_map(
                fn (string $field): string => 'input.'.$field,
                $this->allowedInputFields,
            ),
        ];
    }

    public function responseEnvelopeSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schema_version', 'data'],
            'properties' => [
                'schema_version' => [
                    'type' => 'string',
                    'const' => $this->responseSchemaVersion,
                ],
                'data' => $this->responseDataSchema,
            ],
        ];
    }

    public function responseSchemaName(): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $this->responseSchemaVersion) ?: 'structured_output';

        return substr($name, 0, 64);
    }
}
