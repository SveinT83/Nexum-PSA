<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingContentSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PullWordPressContentSources
{
    public function handle(MarketingCampaign $campaign, string $sourceUrl, int $limit = 5): int
    {
        $endpoint = $this->endpoint($sourceUrl, $limit);
        $response = Http::timeout(15)->acceptJson()->get($endpoint);

        if (! $response->ok() || ! is_array($response->json())) {
            throw new RuntimeException('WordPress content could not be fetched from the provided URL.');
        }

        $posts = collect($response->json())
            ->filter(fn ($post): bool => is_array($post))
            ->take(max(1, min(10, $limit)))
            ->values();

        if ($posts->isEmpty()) {
            throw new RuntimeException('No published WordPress posts were returned from the provided URL.');
        }

        $count = 0;
        $posts->each(function (array $post) use ($campaign, &$count): void {
            $sourceUrl = (string) ($post['link'] ?? '');
            $externalId = filled($post['id'] ?? null) ? (string) $post['id'] : $sourceUrl;

            if ($sourceUrl === '' && $externalId === '') {
                return;
            }

            MarketingContentSource::query()->updateOrCreate(
                [
                    'marketing_campaign_id' => $campaign->id,
                    'source_type' => 'wordpress',
                    'external_id' => $externalId,
                ],
                [
                    'source_url' => $sourceUrl ?: $this->sourceFromGuid($post),
                    'title' => $this->renderedText($post['title']['rendered'] ?? $post['title'] ?? null, 255),
                    'excerpt' => $this->renderedText($post['excerpt']['rendered'] ?? $post['excerpt'] ?? null, 2000),
                    'content_html' => Str::limit($this->renderedHtml($post['content'] ?? null), 12000, ''),
                    'published_at' => $this->publishedAt($post['date_gmt'] ?? $post['date'] ?? null),
                    'fetched_at' => now(),
                    'status' => 'active',
                    'metadata' => [
                        'wordpress_post_id' => $post['id'] ?? null,
                        'wordpress_status' => $post['status'] ?? null,
                        'wordpress_type' => $post['type'] ?? null,
                    ],
                ],
            );

            $count++;
        });

        return $count;
    }

    private function endpoint(string $sourceUrl, int $limit): string
    {
        $url = trim($sourceUrl);

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Enter a valid WordPress site or REST API URL.');
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        $fields = '_fields=id,link,title,excerpt,content,date,date_gmt,status,type';
        $perPage = 'per_page='.max(1, min(10, $limit));

        if (str_contains($url, '/wp-json/wp/v2/posts')) {
            return $url.$separator.$perPage.'&'.$fields;
        }

        return rtrim($url, '/').'/wp-json/wp/v2/posts?'.$perPage.'&'.$fields;
    }

    private function renderedText(mixed $value, int $limit): ?string
    {
        if (is_array($value)) {
            $value = $value['rendered'] ?? '';
        }

        $text = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text !== '' ? Str::limit($text, $limit, '') : null;
    }

    private function renderedHtml(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['rendered'] ?? '';
        }

        return (string) $value;
    }

    private function publishedAt(mixed $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function sourceFromGuid(array $post): string
    {
        $guid = $post['guid'] ?? '';

        if (is_array($guid)) {
            $guid = $guid['rendered'] ?? '';
        }

        return (string) $guid;
    }
}
