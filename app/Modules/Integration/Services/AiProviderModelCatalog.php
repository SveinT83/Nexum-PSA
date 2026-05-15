<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AiProviderModelCatalog
{
    /**
     * Fetch available model IDs from providers that expose a model listing API.
     */
    public function fetch(AiProvider $provider, ?string $apiKey = null): array
    {
        return match ($provider->provider_key) {
            'openai', 'custom_openai_compatible' => $this->openAiCompatible($provider, $apiKey),
            'azure_openai' => $this->azureOpenAi($provider, $apiKey),
            'mistral' => $this->openAiCompatible($provider, $apiKey, 'https://api.mistral.ai/v1'),
            'openrouter' => $this->openAiCompatible($provider, $apiKey, 'https://openrouter.ai/api/v1'),
            'ollama' => $this->ollama($provider),
            'anthropic' => $this->anthropic($provider, $apiKey),
            'google_gemini' => $this->gemini($provider, $apiKey),
            default => [],
        };
    }

    /**
     * Return model suggestions when the provider cannot be queried yet.
     */
    public function defaultsFor(string $providerKey): array
    {
        return match ($providerKey) {
            'openai' => ['gpt-4.1', 'gpt-4.1-mini', 'gpt-4o', 'gpt-4o-mini'],
            'azure_openai' => [],
            'anthropic' => ['claude-3-5-sonnet-latest', 'claude-3-5-haiku-latest'],
            'google_gemini' => ['gemini-1.5-pro', 'gemini-1.5-flash'],
            'mistral' => ['mistral-large-latest', 'mistral-small-latest', 'codestral-latest'],
            'cohere' => ['command-r-plus', 'command-r'],
            'openrouter' => [],
            'ollama' => [],
            'custom_openai_compatible' => [],
            default => [],
        };
    }

    private function openAiCompatible(AiProvider $provider, ?string $apiKey, ?string $defaultBaseUrl = null): array
    {
        $baseUrl = rtrim($provider->base_url ?: $defaultBaseUrl ?: 'https://api.openai.com/v1', '/');
        $request = Http::acceptJson();

        if ($apiKey) {
            $request = $request->withToken($apiKey);
        }

        $response = $request->get($baseUrl.'/models');

        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        return collect($response->json('data', []))
            ->pluck('id')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    private function azureOpenAi(AiProvider $provider, ?string $apiKey): array
    {
        if (! $provider->base_url) {
            throw new RuntimeException('Azure OpenAI endpoint is required before models can be fetched.');
        }

        $apiVersion = $provider->config['api_version'] ?? '2024-02-01';
        $response = Http::acceptJson()
            ->withHeaders(['api-key' => (string) $apiKey])
            ->get(rtrim($provider->base_url, '/').'/openai/deployments', [
                'api-version' => $apiVersion,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        return collect($response->json('data', []))
            ->pluck('id')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    private function ollama(AiProvider $provider): array
    {
        if (! $provider->base_url) {
            throw new RuntimeException('Ollama URL is required before local models can be fetched.');
        }

        $response = Http::acceptJson()->get(rtrim($provider->base_url, '/').'/api/tags');

        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        return collect($response->json('models', []))
            ->pluck('name')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    private function anthropic(AiProvider $provider, ?string $apiKey): array
    {
        $baseUrl = rtrim($provider->base_url ?: 'https://api.anthropic.com/v1', '/');
        $response = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => (string) $apiKey,
                'anthropic-version' => $provider->config['api_version'] ?? '2023-06-01',
            ])
            ->get($baseUrl.'/models');

        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        return collect($response->json('data', []))
            ->pluck('id')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    private function gemini(AiProvider $provider, ?string $apiKey): array
    {
        $baseUrl = rtrim($provider->base_url ?: 'https://generativelanguage.googleapis.com/v1beta', '/');
        $response = Http::acceptJson()->get($baseUrl.'/models', [
            'key' => $apiKey,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        return collect($response->json('models', []))
            ->pluck('name')
            ->map(fn ($name) => Str::after((string) $name, 'models/'))
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    private function failureMessage(int $status, string $body): string
    {
        return 'Model lookup failed with HTTP '.$status.($body !== '' ? ': '.Str::limit($body, 180) : '.');
    }
}
