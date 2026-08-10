<?php

namespace App\Modules\Integration\Services\BookStack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

class BookStackClient
{
    private const PAGE_SIZE = 100;

    /**
     * Minimum seconds between API requests to stay under BookStack's rate limit.
     * BookStack defaults to 180 requests/minute (3/sec); 350ms gives safe headroom.
     */
    private const DEFAULT_REQUEST_DELAY_SECONDS = 0.35;

    /**
     * Maximum retries for HTTP 429 (Too Many Attempts) responses.
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Base delay in seconds for exponential backoff on 429 responses.
     * Actual delay: base * 2^attempt (2s, 4s, 8s).
     */
    private const RETRY_BASE_DELAY_SECONDS = 2;

    /**
     * Timestamp of the last API request, used to enforce minimum inter-request delay.
     */
    private static float $lastRequestTime = 0.0;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $tokenId,
        private readonly string $tokenSecret,
        private readonly float $requestDelaySeconds = self::DEFAULT_REQUEST_DELAY_SECONDS,
        private readonly int $maxRetries = self::MAX_RETRY_ATTEMPTS,
    ) {}

    public function testConnection(): array
    {
        try {
            $response = $this->getWithRateLimit('/api/books', [
                'count' => 1,
            ]);
        } catch (ConnectionException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }

        if ($response->successful()) {
            return [
                'success' => true,
                'message' => null,
            ];
        }

        return [
            'success' => false,
            'message' => $response->json('message') ?: 'BookStack API returned HTTP '.$response->status().'.',
        ];
    }

    /**
     * Fetch every BookStack shelf visible to the configured API token.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allShelves(): array
    {
        return $this->allFromEndpoint('/api/shelves', 'Unable to list BookStack shelves');
    }

    /**
     * Read a single shelf so hierarchy sync can capture assigned books.
     *
     * @return array<string, mixed>
     */
    public function readShelf(int|string $shelfId): array
    {
        $response = $this->getWithRateLimit('/api/shelves/'.$shelfId);

        $this->ensureSuccessful($response, 'Unable to read BookStack shelf '.$shelfId);

        return $response->json() ?? [];
    }

    /**
     * Fetch every BookStack book visible to the configured API token.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allBooks(): array
    {
        return $this->allFromEndpoint('/api/books', 'Unable to list BookStack books');
    }

    /**
     * Read a single book so hierarchy sync can capture description and contents.
     *
     * @return array<string, mixed>
     */
    public function readBook(int|string $bookId): array
    {
        $response = $this->getWithRateLimit('/api/books/'.$bookId);

        $this->ensureSuccessful($response, 'Unable to read BookStack book '.$bookId);

        return $response->json() ?? [];
    }

    /**
     * Create a shelf in BookStack.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createShelf(array $payload): array
    {
        $response = $this->postWithRateLimit('/api/shelves', $payload);

        $this->ensureSuccessful($response, 'Unable to create BookStack shelf');

        return $response->json() ?? [];
    }

    /**
     * Update shelf metadata or book membership in BookStack.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateShelf(int|string $shelfId, array $payload): array
    {
        $response = $this->putWithRateLimit('/api/shelves/'.$shelfId, $payload);

        $this->ensureSuccessful($response, 'Unable to update BookStack shelf '.$shelfId);

        return $response->json() ?? [];
    }

    /**
     * Delete an existing shelf in BookStack.
     */
    public function deleteShelf(int|string $shelfId): void
    {
        $response = $this->deleteWithRateLimit('/api/shelves/'.$shelfId);

        $this->ensureSuccessful($response, 'Unable to delete BookStack shelf '.$shelfId);
    }

    /**
     * Create a book in BookStack.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createBook(array $payload): array
    {
        $response = $this->postWithRateLimit('/api/books', $payload);

        $this->ensureSuccessful($response, 'Unable to create BookStack book');

        return $response->json() ?? [];
    }

    /**
     * Update an existing book in BookStack.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateBook(int|string $bookId, array $payload): array
    {
        $response = $this->putWithRateLimit('/api/books/'.$bookId, $payload);

        $this->ensureSuccessful($response, 'Unable to update BookStack book '.$bookId);

        return $response->json() ?? [];
    }

    /**
     * Delete an existing book in BookStack.
     */
    public function deleteBook(int|string $bookId): void
    {
        $response = $this->deleteWithRateLimit('/api/books/'.$bookId);

        if ($response->status() === 404) {
            return;
        }

        $this->ensureSuccessful($response, 'Unable to delete BookStack book '.$bookId);
    }

    /**
     * Fetch every BookStack page visible to the configured API token.
     *
     * BookStack listing endpoints expose `data`, `total`, `count`, and `offset`.
     * Keeping pagination here prevents sync actions from duplicating API mechanics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allPages(): array
    {
        return $this->allFromEndpoint('/api/pages', 'Unable to list BookStack pages');
    }

    /**
     * Fetch every BookStack chapter visible to the configured API token.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allChapters(): array
    {
        return $this->allFromEndpoint('/api/chapters', 'Unable to list BookStack chapters');
    }

    /**
     * Read a single chapter when page payloads only provide a chapter ID.
     *
     * @return array<string, mixed>
     */
    public function readChapter(int|string $chapterId): array
    {
        $response = $this->getWithRateLimit('/api/chapters/'.$chapterId);

        $this->ensureSuccessful($response, 'Unable to read BookStack chapter '.$chapterId);

        return $response->json() ?? [];
    }

    /**
     * Create a chapter in BookStack.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createChapter(array $payload): array
    {
        $response = $this->postWithRateLimit('/api/chapters', $payload);

        $this->ensureSuccessful($response, 'Unable to create BookStack chapter');

        return $response->json() ?? [];
    }

    /**
     * Update an existing chapter in BookStack.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateChapter(int|string $chapterId, array $payload): array
    {
        $response = $this->putWithRateLimit('/api/chapters/'.$chapterId, $payload);

        $this->ensureSuccessful($response, 'Unable to update BookStack chapter '.$chapterId);

        return $response->json() ?? [];
    }

    /**
     * Delete an existing chapter in BookStack.
     */
    public function deleteChapter(int|string $chapterId): void
    {
        $response = $this->deleteWithRateLimit('/api/chapters/'.$chapterId);

        if ($response->status() === 404) {
            return;
        }

        $this->ensureSuccessful($response, 'Unable to delete BookStack chapter '.$chapterId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allFromEndpoint(string $path, string $failureMessage): array
    {
        $pages = [];
        $offset = 0;

        do {
            $response = $this->getWithRateLimit($path, [
                'count' => self::PAGE_SIZE,
                'offset' => $offset,
                'sort' => '+id',
            ]);

            $this->ensureSuccessful($response, $failureMessage);

            $data = $response->json('data') ?? [];

            if (! is_array($data)) {
                throw new RuntimeException($failureMessage.': BookStack returned an invalid list response.');
            }

            $total = (int) ($response->json('total') ?? count($data));
            $pages = array_merge($pages, $data);
            $offset += self::PAGE_SIZE;
        } while ($offset < $total);

        return $pages;
    }

    /**
     * Read a single page so the sync can store rendered content, source metadata,
     * and tags instead of only the lightweight list response.
     *
     * @return array<string, mixed>
     */
    public function readPage(int|string $pageId): array
    {
        $response = $this->getWithRateLimit('/api/pages/'.$pageId);

        $this->ensureSuccessful($response, 'Unable to read BookStack page '.$pageId);

        return $response->json() ?? [];
    }

    /**
     * Create a page in BookStack.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createPage(array $payload): array
    {
        $response = $this->postWithRateLimit('/api/pages', $payload);

        $this->ensureSuccessful($response, 'Unable to create BookStack page');

        return $response->json() ?? [];
    }

    /**
     * Update an existing page in BookStack.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updatePage(int|string $pageId, array $payload): array
    {
        $response = $this->putWithRateLimit('/api/pages/'.$pageId, $payload);

        $this->ensureSuccessful($response, 'Unable to update BookStack page '.$pageId);

        return $response->json() ?? [];
    }

    /**
     * Delete an existing page in BookStack.
     */
    public function deletePage(int|string $pageId): void
    {
        $response = $this->deleteWithRateLimit('/api/pages/'.$pageId);

        if ($response->status() === 404) {
            return;
        }

        $this->ensureSuccessful($response, 'Unable to delete BookStack page '.$pageId);
    }

    /**
     * Build the base HTTP request with authentication headers.
     *
     * Does NOT send the request — callers chain ->get()/->post()/etc.
     * Rate-limit delay is applied in sendWithRateLimit().
     */
    private function request()
    {
        return Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'Authorization' => 'Token '.$this->tokenId.':'.$this->tokenSecret,
            ])
            ->timeout(15);
    }

    /**
     * Send a GET request with inter-request delay and 429 retry/backoff.
     *
     * @param  string  $path  API endpoint path (e.g. '/api/pages')
     * @param  array<string,mixed>  $query  Query parameters
     * @return Response
     *
     * @throws RuntimeException On non-2xx responses after exhausting retries
     * @throws ConnectionException On network failures
     */
    private function getWithRateLimit(string $path, array $query = []): Response
    {
        return $this->sendWithRateLimit('GET', $path, $query);
    }

    /**
     * Send a POST request with inter-request delay and 429 retry/backoff.
     *
     * @param  string  $path  API endpoint path
     * @param  array<string,mixed>  $payload  JSON body
     * @return Response
     *
     * @throws RuntimeException On non-2xx responses after exhausting retries
     * @throws ConnectionException On network failures
     */
    private function postWithRateLimit(string $path, array $payload = []): Response
    {
        return $this->sendWithRateLimit('POST', $path, [], $payload);
    }

    /**
     * Send a PUT request with inter-request delay and 429 retry/backoff.
     *
     * @param  string  $path  API endpoint path
     * @param  array<string,mixed>  $payload  JSON body
     * @return Response
     *
     * @throws RuntimeException On non-2xx responses after exhausting retries
     * @throws ConnectionException On network failures
     */
    private function putWithRateLimit(string $path, array $payload = []): Response
    {
        return $this->sendWithRateLimit('PUT', $path, [], $payload);
    }

    /**
     * Send a DELETE request with inter-request delay and 429 retry/backoff.
     *
     * @param  string  $path  API endpoint path
     * @return Response
     *
     * @throws RuntimeException On non-2xx responses after exhausting retries
     * @throws ConnectionException On network failures
     */
    private function deleteWithRateLimit(string $path): Response
    {
        return $this->sendWithRateLimit('DELETE', $path);
    }

    /**
     * Core request sender with inter-request pacing and 429 exponential backoff.
     *
     * Enforces a minimum delay between requests (DEFAULT_REQUEST_DELAY_SECONDS)
     * to stay under BookStack's 180 req/min rate limit. On HTTP 429, retries
     * with exponential backoff up to maxRetries attempts.
     *
     * @param  'GET'|'POST'|'PUT'|'DELETE'  $method
     * @param  string  $path  API endpoint path
     * @param  array<string,mixed>  $query  Query parameters (GET only)
     * @param  array<string,mixed>  $payload  JSON body (POST/PUT only)
     *
     * @throws RuntimeException On non-429 failures or after exhausting 429 retries
     * @throws ConnectionException On network failures
     */
    private function sendWithRateLimit(string $method, string $path, array $query = [], array $payload = []): Response
    {
        $url = $this->endpoint($path);
        $attempts = 0;

        while (true) {
            $this->paceRequest();

            $request = $this->request();

            $response = match ($method) {
                'GET' => $request->get($url, $query),
                'POST' => $request->post($url, $payload),
                'PUT' => $request->put($url, $payload),
                'DELETE' => $request->delete($url),
            };

            $attempts++;

            if ($response->status() === 429) {
                if ($attempts >= $this->maxRetries) {
                    $retryAfter = $response->header('Retry-After');
                    $waited = self::RETRY_BASE_DELAY_SECONDS * (2 ** $this->maxRetries);
                    throw new RuntimeException(
                        "BookStack API rate limit exceeded after {$attempts} attempts"
                        ." (waited ~{$waited}s total). Consider increasing sync_interval_minutes"
                        ." or reducing the number of synced pages."
                        .($retryAfter ? " Retry-After header: {$retryAfter}s." : '')
                    );
                }

                $backoffSeconds = self::RETRY_BASE_DELAY_SECONDS * (2 ** ($attempts - 1));
                $retryAfter = $response->header('Retry-After');

                if ($retryAfter && is_numeric($retryAfter)) {
                    $backoffSeconds = max($backoffSeconds, (float) $retryAfter);
                }

                Sleep::for($backoffSeconds)->seconds();

                continue;
            }

            return $response;
        }
    }

    /**
     * Enforce minimum inter-request delay by sleeping if the last request was too recent.
     *
     * BookStack's default rate limit is 180 requests/minute (3/sec).
     * A 350ms delay between requests provides safe headroom.
     */
    private function paceRequest(): void
    {
        $elapsed = microtime(true) - self::$lastRequestTime;

        if ($elapsed < $this->requestDelaySeconds && self::$lastRequestTime > 0) {
            $sleepSeconds = $this->requestDelaySeconds - $elapsed;
            usleep((int) ($sleepSeconds * 1_000_000));
        }

        self::$lastRequestTime = microtime(true);
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function ensureSuccessful(Response $response, string $fallbackMessage): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message')
            ?: $response->json('message')
            ?: $fallbackMessage.' (HTTP '.$response->status().').';

        throw new RuntimeException($message);
    }
}
