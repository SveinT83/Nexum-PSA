<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Modules\Integration\Models\AiAgent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SearchLeadWebWithAi
{
    private const TIMEOUT_SECONDS = 180;

    public function search(string $query, int $limit, array $context = []): array
    {
        $agent = $this->agent();

        if (! $agent) {
            throw new RuntimeException('No active Lead Intelligence AI agent is available for web search.');
        }

        $provider = $agent->provider;

        if ($provider?->provider_key !== 'openai') {
            throw new RuntimeException('AI web search requires an active OpenAI provider on the Lead Intelligence agent.');
        }

        $apiKey = $provider->getSecret('api_key');
        $model = $agent->model ?: $provider->default_model;

        if (! $apiKey) {
            throw new RuntimeException('API key is missing for the Lead Intelligence OpenAI provider.');
        }

        if (! $model) {
            throw new RuntimeException('Select a model for the Lead Intelligence agent or provider before web search.');
        }

        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->timeout(self::TIMEOUT_SECONDS)
            ->post(rtrim((string) ($provider->base_url ?: 'https://api.openai.com/v1'), '/').'/responses', [
                'model' => $model,
                'input' => $this->prompt($query, $limit, $context),
                'tools' => [
                    [
                        'type' => 'web_search',
                        'search_context_size' => 'low',
                    ],
                ],
                'tool_choice' => 'required',
                'max_output_tokens' => 2000,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        $payload = $response->json();
        $content = $this->responseOutputText($payload);
        $results = $this->resultsFromJson($content, $limit);

        if ($results === []) {
            $results = $this->resultsFromAnnotations($payload, $limit);
        }

        if ($results === []) {
            $results = $this->resultsFromText($content, $limit);
        }

        return collect($results)
            ->map(function (array $result) use ($query): ?array {
                $url = $this->normalizeUrl($result['url'] ?? null);

                if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
                    return null;
                }

                return [
                    'title' => Str::limit(trim((string) ($result['title'] ?? parse_url($url, PHP_URL_HOST) ?? $url)), 180, ''),
                    'url' => $url,
                    'snippet' => Str::limit(trim((string) ($result['snippet'] ?? $result['reason'] ?? '')), 500, ''),
                    'source' => 'ai_web_search',
                    'query' => $query,
                ];
            })
            ->filter()
            ->unique('url')
            ->take(max(1, $limit))
            ->values()
            ->all();
    }

    private function agent(): ?AiAgent
    {
        return AiAgent::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->first(fn (AiAgent $agent): bool => in_array('lead_intelligence', $agent->default_domains ?? [], true));
    }

    private function prompt(string $query, int $limit, array $context): string
    {
        return trim(<<<PROMPT
You are Nexum PSA Lead Intelligence web search for Norwegian B2B prospecting.

Search the public web for official company websites, contact pages, employee/team pages, and shared company mailbox pages that match the query.
Return ONLY compact JSON with this shape:
{"results":[{"title":"source title","url":"https://official-or-contact-page.example","snippet":"short evidence note"}]}

Rules:
- Return at most {$limit} results.
- Prefer the company's own website and its contact/about/team pages.
- Include only URLs discovered by web search. Do not invent URLs, people, companies, roles, or email addresses.
- Avoid directory/profile/social results unless no official website appears relevant.
- Prefer Norwegian pages with kontakt, ansatte, medarbeidere, daglig leder, post, info, firmapost, or organisasjonsnummer evidence.

Query:
{$query}

Run context:
{$this->json($context)}
PROMPT);
    }

    private function json(array $context): string
    {
        $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json ?: '{}';
    }

    private function resultsFromJson(string $content, int $limit): array
    {
        $json = trim($content);

        if ($json === '') {
            return [];
        }

        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*/i', '', $json);
            $json = preg_replace('/\s*```$/', '', (string) $json);
        }

        $decoded = json_decode((string) $json, true);

        if (! is_array($decoded)) {
            preg_match('/\{.*\}/s', $json, $matches);
            $decoded = isset($matches[0]) ? json_decode($matches[0], true) : null;
        }

        if (! is_array($decoded)) {
            return [];
        }

        $items = $decoded['results'] ?? $decoded;

        return collect((array) $items)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->take(max(1, $limit))
            ->values()
            ->all();
    }

    private function resultsFromAnnotations(array $payload, int $limit): array
    {
        return collect($this->urlObjects($payload))
            ->map(fn (array $item): array => [
                'title' => $item['title'] ?? $item['url'] ?? '',
                'url' => $item['url'] ?? null,
                'snippet' => $item['snippet'] ?? '',
            ])
            ->filter(fn (array $item): bool => filled($item['url'] ?? null))
            ->unique('url')
            ->take(max(1, $limit))
            ->values()
            ->all();
    }

    private function resultsFromText(string $content, int $limit): array
    {
        preg_match_all('/https?:\/\/[^\s<>"\')]+/i', $content, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $url): array => [
                'title' => parse_url($url, PHP_URL_HOST) ?: $url,
                'url' => $url,
                'snippet' => 'URL returned by AI web search.',
            ])
            ->unique('url')
            ->take(max(1, $limit))
            ->values()
            ->all();
    }

    private function urlObjects(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $results = [];

        if (filled($value['url'] ?? null)) {
            $results[] = $value;
        }

        foreach ($value as $item) {
            if (is_array($item)) {
                $results = array_merge($results, $this->urlObjects($item));
            }
        }

        return $results;
    }

    private function responseOutputText(array $payload): string
    {
        $direct = data_get($payload, 'output_text')
            ?: data_get($payload, 'message.content')
            ?: data_get($payload, 'choices.0.message.content');

        if (filled($direct)) {
            return (string) $direct;
        }

        return collect(data_get($payload, 'output', []))
            ->flatMap(function (array $item): array {
                $content = $item['content'] ?? [];

                if (is_string($content)) {
                    return [['text' => $content]];
                }

                return (array) $content;
            })
            ->map(function (mixed $content): ?string {
                if (is_string($content)) {
                    return $content;
                }

                if (! is_array($content)) {
                    return null;
                }

                $text = $content['text'] ?? $content['content'] ?? null;

                if (is_array($text)) {
                    $text = collect($text)
                        ->map(fn (mixed $part): ?string => is_array($part) ? ($part['text'] ?? $part['content'] ?? null) : (is_string($part) ? $part : null))
                        ->filter()
                        ->implode("\n");
                }

                return is_string($text) ? $text : null;
            })
            ->filter()
            ->implode("\n");
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $url = trim((string) $value);

        if ($url === '') {
            return null;
        }

        $url = Str::startsWith($url, ['http://', 'https://']) ? $url : 'https://'.$url;

        return filter_var($url, FILTER_VALIDATE_URL) ? rtrim($url, '/') : null;
    }

    private function failureMessage(int $status, string $body): string
    {
        return 'AI web search failed with HTTP '.$status.($body !== '' ? ': '.Str::limit($body, 220) : '');
    }
}
