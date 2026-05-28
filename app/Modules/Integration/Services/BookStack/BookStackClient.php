<?php

namespace App\Modules\Integration\Services\BookStack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BookStackClient
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $tokenId,
        private readonly string $tokenSecret,
    ) {}

    public function testConnection(): array
    {
        try {
            $response = $this->request()->get($this->endpoint('/api/books'), [
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
        $response = $this->request()->get($this->endpoint('/api/shelves/'.$shelfId));

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
        $response = $this->request()->get($this->endpoint('/api/books/'.$bookId));

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
        $response = $this->request()->post($this->endpoint('/api/shelves'), $payload);

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
        $response = $this->request()->put($this->endpoint('/api/shelves/'.$shelfId), $payload);

        $this->ensureSuccessful($response, 'Unable to update BookStack shelf '.$shelfId);

        return $response->json() ?? [];
    }

    /**
     * Delete an existing shelf in BookStack.
     */
    public function deleteShelf(int|string $shelfId): void
    {
        $response = $this->request()->delete($this->endpoint('/api/shelves/'.$shelfId));

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
        $response = $this->request()->post($this->endpoint('/api/books'), $payload);

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
        $response = $this->request()->put($this->endpoint('/api/books/'.$bookId), $payload);

        $this->ensureSuccessful($response, 'Unable to update BookStack book '.$bookId);

        return $response->json() ?? [];
    }

    /**
     * Delete an existing book in BookStack.
     */
    public function deleteBook(int|string $bookId): void
    {
        $response = $this->request()->delete($this->endpoint('/api/books/'.$bookId));

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
        $response = $this->request()->get($this->endpoint('/api/chapters/'.$chapterId));

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
        $response = $this->request()->post($this->endpoint('/api/chapters'), $payload);

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
        $response = $this->request()->put($this->endpoint('/api/chapters/'.$chapterId), $payload);

        $this->ensureSuccessful($response, 'Unable to update BookStack chapter '.$chapterId);

        return $response->json() ?? [];
    }

    /**
     * Delete an existing chapter in BookStack.
     */
    public function deleteChapter(int|string $chapterId): void
    {
        $response = $this->request()->delete($this->endpoint('/api/chapters/'.$chapterId));

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
            $response = $this->request()
                ->get($this->endpoint($path), [
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
        $response = $this->request()->get($this->endpoint('/api/pages/'.$pageId));

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
        $response = $this->request()->post($this->endpoint('/api/pages'), $payload);

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
        $response = $this->request()->put($this->endpoint('/api/pages/'.$pageId), $payload);

        $this->ensureSuccessful($response, 'Unable to update BookStack page '.$pageId);

        return $response->json() ?? [];
    }

    private function request()
    {
        return Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'Authorization' => 'Token '.$this->tokenId.':'.$this->tokenSecret,
            ])
            ->timeout(15);
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
