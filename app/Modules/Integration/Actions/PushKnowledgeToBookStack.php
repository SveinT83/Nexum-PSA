<?php

namespace App\Modules\Integration\Actions;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Models\Knowledge\Shelf;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Services\BookStack\BookStackClient;
use Illuminate\Support\Arr;

/**
 * Pushes locally-owned Knowledge content into BookStack.
 *
 * This is the first two-way sync path: local shelves, books, and pages are
 * created in BookStack, then marked as BookStack-backed records in Nexum PSA.
 */
class PushKnowledgeToBookStack
{
    public function __construct(
        private readonly Integration $integration,
        private readonly BookStackClient $client,
    ) {}

    /**
     * @return array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}
     */
    public function execute(): array
    {
        $summary = [
            'shelves' => 0,
            'books' => 0,
            'chapters' => 0,
            'pages' => 0,
            'skipped' => 0,
            'failed' => 0,
            'total' => 0,
            'errors' => [],
        ];

        $this->pushShelves($summary);
        $this->pushBooks($summary);
        $this->syncShelfBookMemberships($summary);
        $this->pushChapters($summary);
        $this->pushPages($summary);
        $this->recordSummary($summary);

        return $summary;
    }

    /**
     * @param  array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}  $summary
     */
    private function pushShelves(array &$summary): void
    {
        Shelf::query()
            ->where('sync_status', 'pending_push')
            ->where(function ($query): void {
                $query->whereNull('source_system')
                    ->orWhere(function ($query): void {
                        $query->where('source_system', 'book_stack')
                            ->where('source_type', 'shelf')
                            ->whereNotNull('source_id');
                    });
            })
            ->orderBy('name')
            ->get()
            ->each(function (Shelf $shelf) use (&$summary): void {
                $summary['total']++;

                try {
                    $payload = [
                        'name' => $shelf->name,
                        'description' => $shelf->description,
                    ];

                    if ($shelf->source_system === 'book_stack' && filled($shelf->source_id)) {
                        $payload['books'] = $this->bookStackBookIdsForShelf($shelf);
                    }

                    $response = $shelf->source_system === 'book_stack' && filled($shelf->source_id)
                        ? $this->client->updateShelf($shelf->source_id, $payload)
                        : $this->client->createShelf($payload);

                    $this->markShelfSynced($shelf, $response);
                    $summary['shelves']++;
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $summary['errors'][] = 'Shelf '.$shelf->id.': '.$exception->getMessage();
                }
            });
    }

    /**
     * @param  array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}  $summary
     */
    private function pushBooks(array &$summary): void
    {
        Book::query()
            ->with('shelf')
            ->where('sync_status', 'pending_push')
            ->where(function ($query): void {
                $query->whereNull('source_system')
                    ->orWhere(function ($query): void {
                        $query->where('source_system', 'book_stack')
                            ->where('source_type', 'book')
                            ->whereNotNull('source_id');
                    });
            })
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->each(function (Book $book) use (&$summary): void {
                $summary['total']++;

                try {
                    $payload = [
                        'name' => $book->name,
                        'description' => $book->description,
                    ];

                    $response = $book->source_system === 'book_stack' && filled($book->source_id)
                        ? $this->client->updateBook($book->source_id, $payload)
                        : $this->client->createBook($payload);

                    $this->markBookSynced($book, $response);
                    $summary['books']++;
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $summary['errors'][] = 'Book '.$book->id.': '.$exception->getMessage();
                }
            });
    }

    /**
     * BookStack assigns books to shelves by updating the shelf's book ID list.
     *
     * @param  array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}  $summary
     */
    private function syncShelfBookMemberships(array &$summary): void
    {
        Shelf::query()
            ->with('books')
            ->where('source_system', 'book_stack')
            ->where('source_type', 'shelf')
            ->get()
            ->each(function (Shelf $shelf) use (&$summary): void {
                $bookIds = $this->bookStackBookIdsForShelf($shelf);

                try {
                    $payload = $this->client->updateShelf($shelf->source_id, [
                        'name' => $shelf->name,
                        'description' => $shelf->description,
                        'books' => $bookIds,
                    ]);

                    $this->markShelfSynced($shelf, $payload);
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $summary['errors'][] = 'Shelf membership '.$shelf->id.': '.$exception->getMessage();
                }
            });
    }

    /**
     * @param  array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}  $summary
     */
    private function pushChapters(array &$summary): void
    {
        Chapter::query()
            ->with('book')
            ->where('sync_status', 'pending_push')
            ->where(function ($query): void {
                $query->whereNull('source_system')
                    ->orWhere(function ($query): void {
                        $query->where('source_system', 'book_stack')
                            ->where('source_type', 'chapter')
                            ->whereNotNull('source_id');
                    })
                    ->orWhere(function ($query): void {
                        $query->where('source_system', 'nexum')
                            ->whereNotNull('source_id');
                    });
            })
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->each(function (Chapter $chapter) use (&$summary): void {
                $summary['total']++;

                try {
                    $isUpdate = $chapter->source_system === 'book_stack' && filled($chapter->source_id);
                    $payload = $this->chapterPayload($chapter, $isUpdate);

                    if ($payload === null) {
                        $summary['skipped']++;
                        $summary['errors'][] = 'Chapter '.$chapter->id.': skipped because its book is not synced to BookStack.';

                        return;
                    }

                    $response = $isUpdate
                        ? $this->client->updateChapter($chapter->source_id, $payload)
                        : $this->client->createChapter($payload);

                    $this->markChapterSynced($chapter, $response);
                    $summary['chapters']++;
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $summary['errors'][] = 'Chapter '.$chapter->id.': '.$exception->getMessage();
                }
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function chapterPayload(Chapter $chapter, bool $isUpdate = false): ?array
    {
        $bookId = $chapter->book?->source_system === 'book_stack'
            ? $chapter->book->source_id
            : null;

        if (! $bookId && ! $isUpdate) {
            return null;
        }

        return array_filter([
            'book_id' => $bookId,
            'name' => $chapter->name,
            'description' => $chapter->description,
            'priority' => $chapter->priority,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}  $summary
     */
    private function pushPages(array &$summary): void
    {
        Article::query()
            ->with(['knowledgeBook', 'knowledgeChapter'])
            ->where('sync_status', 'pending_push')
            ->where(function ($query): void {
                $query->whereNull('source_system')
                    ->orWhere(function ($query): void {
                        $query->where('source_system', 'book_stack')
                            ->where('source_type', 'page')
                            ->whereNotNull('source_id');
                    })
                    ->orWhere(function ($query): void {
                        $query->where('source_system', 'nexum')
                            ->whereNotNull('source_id');
                    });
            })
            ->orderBy('priority')
            ->orderBy('title')
            ->get()
            ->each(function (Article $article) use (&$summary): void {
                $summary['total']++;

                try {
                    $isUpdate = $article->source_system === 'book_stack' && filled($article->source_id);
                    $payload = $this->pagePayload($article, $isUpdate);

                    if ($payload === null) {
                        $summary['skipped']++;
                        $summary['errors'][] = 'Page '.$article->id.': skipped because it has no synced BookStack book or chapter.';

                        return;
                    }

                    $response = $isUpdate
                        ? $this->client->updatePage($article->source_id, $payload)
                        : $this->client->createPage($payload);
                    $this->markPageSynced($article, $response);
                    $summary['pages']++;
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $summary['errors'][] = 'Page '.$article->id.': '.$exception->getMessage();
                }
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pagePayload(Article $article, bool $isUpdate = false): ?array
    {
        if ($article->knowledgeChapter?->source_system === 'book_stack' && $article->knowledgeChapter->source_id) {
            $parent = ['chapter_id' => (int) $article->knowledgeChapter->source_id];
        } elseif ($article->knowledgeBook?->source_system === 'book_stack' && $article->knowledgeBook->source_id) {
            $parent = ['book_id' => (int) $article->knowledgeBook->source_id];
        } elseif ($isUpdate) {
            $parent = [];
        } else {
            return null;
        }

        return $parent + [
            'name' => $article->title,
            'markdown' => $article->body_markdown,
            'priority' => $article->priority,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function bookStackBookIdsForShelf(Shelf $shelf): array
    {
        return $shelf->books
            ->where('source_system', 'book_stack')
            ->pluck('source_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markShelfSynced(Shelf $shelf, array $payload): void
    {
        $shelf->forceFill([
            'name' => (string) Arr::get($payload, 'name', $shelf->name),
            'slug' => (string) Arr::get($payload, 'slug', $shelf->slug),
            'description' => Arr::get($payload, 'description', $shelf->description),
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => (string) Arr::get($payload, 'id', $shelf->source_id),
            'source_url' => $this->shelfUrl($payload),
            'source_checksum' => $this->payloadChecksum($payload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($payload),
            'sync_status' => 'synced',
            'source_payload' => $payload,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markBookSynced(Book $book, array $payload): void
    {
        $book->forceFill([
            'name' => (string) Arr::get($payload, 'name', $book->name),
            'slug' => (string) Arr::get($payload, 'slug', $book->slug),
            'description' => Arr::get($payload, 'description', $book->description),
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => (string) Arr::get($payload, 'id', $book->source_id),
            'source_url' => $this->bookUrl($payload),
            'source_checksum' => $this->payloadChecksum($payload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($payload),
            'sync_status' => 'synced',
            'source_payload' => $payload,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markChapterSynced(Chapter $chapter, array $payload): void
    {
        $chapter->loadMissing('book');

        $chapter->forceFill([
            'name' => (string) Arr::get($payload, 'name', $chapter->name),
            'slug' => (string) Arr::get($payload, 'slug', $chapter->slug),
            'description' => Arr::get($payload, 'description', $chapter->description),
            'source_system' => 'book_stack',
            'source_type' => 'chapter',
            'source_id' => (string) Arr::get($payload, 'id', $chapter->source_id),
            'source_url' => $this->chapterUrl($chapter, $payload),
            'source_checksum' => $this->payloadChecksum($payload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($payload),
            'sync_status' => 'synced',
            'source_payload' => $payload,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markPageSynced(Article $article, array $payload): void
    {
        $article->forceFill([
            'title' => (string) Arr::get($payload, 'name', $article->title),
            'slug' => (string) Arr::get($payload, 'slug', $article->slug),
            'body_markdown' => (string) Arr::get($payload, 'markdown', $article->body_markdown),
            'body_html' => (string) Arr::get($payload, 'html', $article->body_html),
            'source_system' => 'book_stack',
            'source_type' => 'page',
            'source_id' => (string) Arr::get($payload, 'id', $article->source_id),
            'source_url' => $this->pageUrl($article, $payload),
            'source_checksum' => $this->payloadChecksum($payload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($payload),
            'sync_status' => 'synced',
            'source_payload' => $payload,
        ])->save();
    }

    /**
     * @param  array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}  $summary
     */
    private function recordSummary(array $summary): void
    {
        $config = $this->integration->config ?? [];
        $config['last_push_summary'] = $summary;
        $config['last_push_at'] = now()->toIso8601String();
        $this->integration->config = $config;
        $this->integration->last_sync_at = now();
        $this->integration->is_healthy = $summary['failed'] === 0 && $summary['skipped'] === 0;
        $this->integration->last_error = $summary['failed'] === 0 && $summary['skipped'] === 0
            ? null
            : implode("\n", array_slice($summary['errors'], 0, 5));
        $this->integration->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shelfUrl(array $payload): ?string
    {
        $slug = Arr::get($payload, 'slug');

        return $slug ? rtrim((string) $this->integration->server, '/').'/shelves/'.$slug : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function bookUrl(array $payload): ?string
    {
        $slug = Arr::get($payload, 'slug');

        return $slug ? rtrim((string) $this->integration->server, '/').'/books/'.$slug : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function chapterUrl(Chapter $chapter, array $payload): ?string
    {
        $slug = Arr::get($payload, 'slug');
        $bookSlug = Arr::get($payload, 'book.slug')
            ?: Arr::get($payload, 'book_slug')
            ?: Arr::get($chapter->book?->source_payload, 'slug');

        return $bookSlug && $slug
            ? rtrim((string) $this->integration->server, '/').'/books/'.$bookSlug.'/chapter/'.$slug
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pageUrl(Article $article, array $payload): ?string
    {
        if (Arr::get($payload, 'url')) {
            return (string) Arr::get($payload, 'url');
        }

        $bookSlug = Arr::get($payload, 'book.slug')
            ?: Arr::get($payload, 'book_slug')
            ?: Arr::get($article->knowledgeBook?->source_payload, 'slug');
        $pageSlug = Arr::get($payload, 'slug');

        return $bookSlug && $pageSlug
            ? rtrim((string) $this->integration->server, '/').'/books/'.$bookSlug.'/page/'.$pageSlug
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sourceUpdatedAt(array $payload): ?\Illuminate\Support\Carbon
    {
        $updatedAt = Arr::get($payload, 'updated_at');

        return $updatedAt ? \Illuminate\Support\Carbon::parse($updatedAt) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadChecksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
