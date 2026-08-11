<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\StructuredAiProviderUnavailableException;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Support\AiExecutionTrace;
use App\Modules\Integration\Support\AiModelResult;
use App\Modules\Integration\Support\AiModelUsage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class StructuredAiProviderClient
{
    public function __construct(private AiModelExecutor $modelExecutor) {}

    public function execute(
        AiAgent $agent,
        string $model,
        array $messages,
        array $responseSchema,
        string $schemaName,
        AiExecutionTrace $trace,
        int $timeoutSeconds,
        int $maxOutputTokens,
        ?string $reasoningEffort = null,
    ): AiModelResult {
        $provider = $agent->provider;
        if (! $provider || $provider->status !== 'active') {
            throw new StructuredAiProviderUnavailableException('provider_inactive');
        }

        return match ($provider->provider_key) {
            'openai' => $this->openAi(
                agent: $agent,
                baseUrl: $provider->base_url ?: 'https://api.openai.com/v1',
                apiKey: $provider->getSecret('api_key'),
                model: $model,
                messages: $messages,
                responseSchema: $responseSchema,
                schemaName: $schemaName,
                trace: $trace,
                timeoutSeconds: $timeoutSeconds,
                maxOutputTokens: $maxOutputTokens,
                reasoningEffort: $reasoningEffort,
            ),
            'custom_openai_compatible', 'mistral', 'openrouter' => $this->chatCompletions(
                agent: $agent,
                providerKey: $provider->provider_key,
                baseUrl: $provider->base_url ?: $this->defaultBaseUrl($provider->provider_key),
                apiKey: $provider->getSecret('api_key'),
                model: $model,
                messages: $messages,
                responseSchema: $responseSchema,
                schemaName: $schemaName,
                trace: $trace,
                timeoutSeconds: $timeoutSeconds,
                maxOutputTokens: $maxOutputTokens,
            ),
            'ollama' => $this->ollama(
                agent: $agent,
                baseUrl: $provider->base_url,
                model: $model,
                messages: $messages,
                responseSchema: $responseSchema,
                trace: $trace,
                timeoutSeconds: $timeoutSeconds,
                maxOutputTokens: $maxOutputTokens,
            ),
            default => throw new StructuredAiProviderUnavailableException('structured_provider_unsupported'),
        };
    }

    private function openAi(
        AiAgent $agent,
        ?string $baseUrl,
        ?string $apiKey,
        string $model,
        array $messages,
        array $responseSchema,
        string $schemaName,
        AiExecutionTrace $trace,
        int $timeoutSeconds,
        int $maxOutputTokens,
        ?string $reasoningEffort,
    ): AiModelResult {
        if ($this->prefersResponses($model)) {
            return $this->responses(
                agent: $agent,
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                model: $model,
                messages: $messages,
                responseSchema: $responseSchema,
                schemaName: $schemaName,
                trace: $trace,
                timeoutSeconds: min($timeoutSeconds, 165),
                maxOutputTokens: $maxOutputTokens,
                reasoningEffort: $reasoningEffort,
            );
        }

        return $this->chatCompletions(
            agent: $agent,
            providerKey: 'openai',
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            model: $model,
            messages: $messages,
            responseSchema: $responseSchema,
            schemaName: $schemaName,
            trace: $trace,
            timeoutSeconds: $timeoutSeconds,
            maxOutputTokens: $maxOutputTokens,
        );
    }

    private function chatCompletions(
        AiAgent $agent,
        string $providerKey,
        ?string $baseUrl,
        ?string $apiKey,
        string $model,
        array $messages,
        array $responseSchema,
        string $schemaName,
        AiExecutionTrace $trace,
        int $timeoutSeconds,
        int $maxOutputTokens,
    ): AiModelResult {
        if (! $apiKey) {
            throw new StructuredAiProviderUnavailableException('provider_api_key_missing');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $responseSchema,
                ],
            ],
        ];
        $payload[$providerKey === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $maxOutputTokens;

        return $this->modelExecutor->attempt(
            agent: $agent,
            trace: $trace,
            endpointKind: 'structured_chat_completions',
            requestedModel: $model,
            request: fn (): Response => Http::acceptJson()
                ->withToken($apiKey)
                ->timeout($timeoutSeconds)
                ->post(rtrim((string) $baseUrl, '/').'/chat/completions', $payload),
            normalize: fn (Response $response): AiModelResult => $this->openAiResult($response, 'chat'),
        )->result;
    }

    private function responses(
        AiAgent $agent,
        ?string $baseUrl,
        ?string $apiKey,
        string $model,
        array $messages,
        array $responseSchema,
        string $schemaName,
        AiExecutionTrace $trace,
        int $timeoutSeconds,
        int $maxOutputTokens,
        ?string $reasoningEffort,
    ): AiModelResult {
        if (! $apiKey) {
            throw new StructuredAiProviderUnavailableException('provider_api_key_missing');
        }

        $payload = [
            'model' => $model,
            'input' => $messages,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $responseSchema,
                ],
            ],
            'max_output_tokens' => $maxOutputTokens,
        ];
        if ($reasoningEffort !== null) {
            $payload['reasoning'] = ['effort' => $reasoningEffort];
        }

        return $this->modelExecutor->attempt(
            agent: $agent,
            trace: $trace,
            endpointKind: 'structured_responses',
            requestedModel: $model,
            request: fn (): Response => Http::acceptJson()
                ->withToken($apiKey)
                ->timeout($timeoutSeconds)
                ->post(rtrim((string) $baseUrl, '/').'/responses', $payload),
            normalize: fn (Response $response): AiModelResult => $this->openAiResult($response, 'responses'),
        )->result;
    }

    private function ollama(
        AiAgent $agent,
        ?string $baseUrl,
        string $model,
        array $messages,
        array $responseSchema,
        AiExecutionTrace $trace,
        int $timeoutSeconds,
        int $maxOutputTokens,
    ): AiModelResult {
        if (! $baseUrl) {
            throw new StructuredAiProviderUnavailableException('provider_base_url_missing');
        }

        return $this->modelExecutor->attempt(
            agent: $agent,
            trace: $trace,
            endpointKind: 'structured_ollama_chat',
            requestedModel: $model,
            request: fn (): Response => Http::acceptJson()
                ->timeout($timeoutSeconds)
                ->post(rtrim($baseUrl, '/').'/api/chat', [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => false,
                    'format' => $responseSchema,
                    'options' => ['num_predict' => $maxOutputTokens],
                ]),
            normalize: fn (Response $response): AiModelResult => $this->ollamaResult($response),
        )->result;
    }

    private function openAiResult(Response $response, string $endpoint): AiModelResult
    {
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $usage = AiModelUsage::fromOpenAiCompatible($payload);
        $actualModel = $this->stringValue(data_get($payload, 'model'));
        $requestId = $this->requestId($response, $payload);
        $finishReason = $this->stringValue(
            data_get($payload, 'choices.0.finish_reason')
                ?? data_get($payload, 'incomplete_details.reason')
                ?? data_get($payload, 'status'),
        );

        if (! $response->successful()) {
            return AiModelResult::failure(
                usage: $usage,
                actualModel: $actualModel,
                providerRequestId: $requestId,
                finishReason: $finishReason,
                httpStatus: $response->status(),
                errorCategory: 'provider_http_error',
                errorCode: $this->safeCode(data_get($payload, 'error.code')) ?: 'http_'.$response->status(),
            );
        }

        $content = $endpoint === 'responses'
            ? $this->responsesText($payload)
            : data_get($payload, 'choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            return AiModelResult::failure(
                usage: $usage,
                actualModel: $actualModel,
                providerRequestId: $requestId,
                finishReason: $finishReason,
                httpStatus: $response->status(),
                errorCategory: 'empty_response',
            );
        }

        return AiModelResult::success(
            content: trim($content),
            usage: $usage,
            actualModel: $actualModel,
            providerRequestId: $requestId,
            finishReason: $finishReason,
            httpStatus: $response->status(),
        );
    }

    private function ollamaResult(Response $response): AiModelResult
    {
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $usage = AiModelUsage::fromOllama($payload);
        $content = data_get($payload, 'message.content');

        if (! $response->successful() || ! is_string($content) || trim($content) === '') {
            return AiModelResult::failure(
                usage: $usage,
                actualModel: $this->stringValue(data_get($payload, 'model')),
                providerRequestId: $this->requestId($response, $payload),
                finishReason: $this->stringValue(data_get($payload, 'done_reason')),
                httpStatus: $response->status(),
                errorCategory: $response->successful() ? 'empty_response' : 'provider_http_error',
                errorCode: $response->successful() ? null : 'http_'.$response->status(),
            );
        }

        return AiModelResult::success(
            content: trim($content),
            usage: $usage,
            actualModel: $this->stringValue(data_get($payload, 'model')),
            providerRequestId: $this->requestId($response, $payload),
            finishReason: $this->stringValue(data_get($payload, 'done_reason')),
            httpStatus: $response->status(),
        );
    }

    private function responsesText(array $payload): ?string
    {
        $direct = data_get($payload, 'output_text');
        if (is_string($direct) && trim($direct) !== '') {
            return $direct;
        }

        $parts = collect(data_get($payload, 'output', []))
            ->flatMap(fn (mixed $item): array => is_array($item) ? (array) ($item['content'] ?? []) : [])
            ->map(fn (mixed $part): ?string => is_array($part) ? ($part['text'] ?? null) : null)
            ->filter(fn (mixed $part): bool => is_string($part) && trim($part) !== '')
            ->implode("\n");

        return $parts !== '' ? $parts : null;
    }

    private function requestId(Response $response, array $payload): ?string
    {
        return $this->stringValue(data_get($payload, 'id'))
            ?: $this->stringValue($response->header('x-request-id'))
            ?: $this->stringValue($response->header('openai-request-id'));
    }

    private function safeCode(mixed $value): ?string
    {
        $value = $this->stringValue($value);
        if ($value === null) {
            return null;
        }

        return substr(preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $value) ?: '', 0, 80) ?: null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function prefersResponses(string $model): bool
    {
        return Str::startsWith(Str::lower($model), [
            'gpt-5',
            'o1',
            'o3',
            'o4',
            'computer-use',
            'codex',
        ]);
    }

    private function defaultBaseUrl(string $providerKey): string
    {
        return match ($providerKey) {
            'mistral' => 'https://api.mistral.ai/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            default => 'https://api.openai.com/v1',
        };
    }
}
