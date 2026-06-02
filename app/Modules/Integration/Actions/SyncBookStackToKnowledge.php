<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Models\Knowledge\Shelf;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Services\BookStack\BookStackClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SyncBookStackToKnowledge
{
    public function __construct(
        private readonly Integration $integration,
        private readonly BookStackClient $client,
        private readonly User $actor,
    ) {}

    /**
     * Pull BookStack pages into Knowledge while keeping Nexum PSA as the local
     * ownership and review system for synchronized content.
     *
     * @return array{created: int, updated: int, skipped: int, failed: int, total: int, errors: array<int, string>}
     */
    public function execute(): array
    {
        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'total' => 0,
            'errors' => [],
        ];

        $hierarchy = $this->syncHierarchy();

        foreach ($this->client->allPages() as $listedPage) {
            $summary['total']++;
            $pageId = (string) Arr::get($listedPage, 'id');

            try {
                $page = $this->client->readPage($pageId);
                $result = $this->upsertPage($page, $hierarchy);
                $summary[$result]++;
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $summary['errors'][] = 'Page '.($pageId ?: 'unknown').': '.$exception->getMessage();
            }
        }

        $config = $this->integration->config ?? [];
        $config['last_sync_summary'] = $summary;
        $config['last_pull_at'] = now()->toIso8601String();
        $this->integration->config = $config;
        $this->integration->last_sync_at = now();
        $this->integration->is_healthy = $summary['failed'] === 0;
        $this->integration->last_error = $summary['failed'] === 0
            ? null
            : implode("\n", array_slice($summary['errors'], 0, 5));
        $this->integration->save();

        return $summary;
    }

    /**
     * Synchronize BookStack shelves and books before pages are imported.
     *
     * @return array{default_shelf: Shelf, books: array<string, Book>, chapters: array<string, Chapter>, shelf_books: array<string, Shelf>}
     */
    private function syncHierarchy(): array
    {
        $shelfBookMap = [];
        $defaultShelf = $this->upsertDefaultShelf();
        $usedDefaultShelf = false;

        foreach ($this->client->allShelves() as $listedShelfPayload) {
            $shelfPayload = $this->client->readShelf((string) Arr::get($listedShelfPayload, 'id'));
            $shelf = $this->upsertShelf($shelfPayload);

            foreach (Arr::get($shelfPayload, 'books', []) as $bookPayload) {
                $bookId = (string) Arr::get($bookPayload, 'id');

                if ($bookId !== '') {
                    $shelfBookMap[$bookId] = $shelf;
                }
            }
        }

        $books = [];

        foreach ($this->client->allBooks() as $bookPayload) {
            $bookId = (string) Arr::get($bookPayload, 'id');
            $shelf = $shelfBookMap[$bookId] ?? $defaultShelf;
            $usedDefaultShelf = $usedDefaultShelf || $shelf->is($defaultShelf);
            $books[$bookId] = $this->upsertBook($bookPayload, $shelf);
        }

        $chapters = $this->syncChapters($books);

        if (! $usedDefaultShelf && ! $defaultShelf->books()->exists()) {
            $defaultShelf->delete();
        }

        return [
            'default_shelf' => $defaultShelf,
            'books' => $books,
            'chapters' => $chapters,
            'shelf_books' => $shelfBookMap,
        ];
    }

    private function upsertPage(array $page, array $hierarchy): string
    {
        $sourceId = (string) Arr::get($page, 'id');
        $checksum = $this->checksum($page);
        $book = $this->bookForPage($page, $hierarchy);
        $chapter = $this->chapterForPage($page, $book, $hierarchy);

        $article = Article::withTrashed()
            ->where('source_system', 'book_stack')
            ->where('source_type', 'page')
            ->where('source_id', $sourceId)
            ->first();

        if ($article && $article->source_checksum === $checksum && ! $article->trashed()) {
            $article->forceFill([
                'knowledge_shelf_id' => $book?->shelf_id,
                'knowledge_book_id' => $book?->id,
                'knowledge_chapter_id' => $chapter?->id,
                'priority' => (int) Arr::get($page, 'priority', 0),
                'source_synced_at' => now(),
                'sync_status' => 'synced',
            ])->save();

            return 'skipped';
        }

        $wasRecentlyCreated = false;

        if (! $article) {
            $article = new Article;
            $article->created_by = $this->actor->id;
            $article->view_count = 0;
            $wasRecentlyCreated = true;
        } elseif ($article->trashed()) {
            $article->restore();
        }

        $html = (string) Arr::get($page, 'html', '');
        $markdown = trim((string) Arr::get($page, 'markdown', ''));

        $article->fill([
            'title' => (string) Arr::get($page, 'name', 'BookStack page '.$sourceId),
            'slug' => $this->articleSlug($page),
            'body_markdown' => $markdown !== '' ? $markdown : $this->plainTextFromHtml($html),
            'body_html' => $html,
            'visibility' => 'internal',
            'status' => Arr::get($page, 'draft') ? 'draft' : 'published',
            'owner_id' => $this->actor->id,
            'knowledge_shelf_id' => $book?->shelf_id,
            'knowledge_book_id' => $book?->id,
            'knowledge_chapter_id' => $chapter?->id,
            'priority' => (int) Arr::get($page, 'priority', 0),
            'updated_by' => $this->actor->id,
            'next_review_at' => now()->addYear(),
            'source_system' => 'book_stack',
            'source_type' => 'page',
            'source_id' => $sourceId,
            'source_url' => $this->sourceUrl($page),
            'source_checksum' => $checksum,
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($page),
            'sync_status' => 'synced',
            'source_payload' => $this->sourcePayload($page),
        ]);

        $article->save();

        return $wasRecentlyCreated ? 'created' : 'updated';
    }

    private function upsertDefaultShelf(): Shelf
    {
        $shelf = $this->findShelfBySourceOrSlug('virtual_shelf', 'default', 'bookstack') ?? new Shelf;

        $shelf->forceFill([
            'name' => 'BookStack',
            'slug' => 'bookstack',
            'description' => 'Imported BookStack books that are not assigned to a shelf.',
            'source_system' => 'book_stack',
            'source_type' => 'virtual_shelf',
            'source_id' => 'default',
            'source_url' => rtrim((string) $this->integration->server, '/'),
            'source_checksum' => hash('sha256', 'bookstack-default-shelf'),
            'source_synced_at' => now(),
            'sync_status' => 'synced',
            'source_payload' => ['virtual' => true],
        ])->save();

        return $shelf;
    }

    private function upsertShelf(array $payload): Shelf
    {
        $sourceId = (string) Arr::get($payload, 'id');
        $slug = $this->sourceSlug('bookstack-shelf', $payload);
        $shelf = $this->findShelfBySourceOrSlug('shelf', $sourceId, $slug) ?? new Shelf;

        $shelf->forceFill([
            'name' => (string) Arr::get($payload, 'name', 'BookStack shelf '.$sourceId),
            'slug' => $slug,
            'description' => $this->descriptionFromPayload($payload),
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => $sourceId,
            'source_url' => $this->shelfUrl($payload),
            'source_checksum' => $this->payloadChecksum($payload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($payload),
            'sync_status' => 'synced',
            'source_payload' => Arr::only($payload, ['id', 'name', 'slug', 'description', 'description_html', 'books']),
        ])->save();

        return $shelf;
    }

    private function upsertBook(array $payload, Shelf $shelf): Book
    {
        $sourceId = (string) Arr::get($payload, 'id');
        $slug = $this->sourceSlug('bookstack-book', $payload);
        $book = $this->findBookBySourceOrSlug('book', $sourceId, $slug) ?? new Book;

        $book->forceFill([
            'shelf_id' => $shelf->id,
            'name' => (string) Arr::get($payload, 'name', 'BookStack book '.$sourceId),
            'slug' => $slug,
            'description' => $this->descriptionFromPayload($payload),
            'priority' => (int) Arr::get($payload, 'priority', 0),
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => $sourceId,
            'source_url' => $this->bookUrl($payload),
            'source_checksum' => $this->payloadChecksum($payload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($payload),
            'sync_status' => 'synced',
            'source_payload' => Arr::only($payload, ['id', 'name', 'slug', 'description', 'description_html', 'created_at', 'updated_at']),
        ])->save();

        return $book;
    }

    private function bookForPage(array $page, array $hierarchy): ?Book
    {
        $bookId = (string) Arr::get($page, 'book_id', Arr::get($page, 'book.id'));

        if ($bookId !== '' && isset($hierarchy['books'][$bookId])) {
            return $hierarchy['books'][$bookId];
        }

        $bookPayload = Arr::get($page, 'book');

        if (is_array($bookPayload) && Arr::get($bookPayload, 'id')) {
            return $this->upsertBook($bookPayload, $hierarchy['default_shelf']);
        }

        return null;
    }

    /**
     * @param array<string, Book> $books
     * @return array<string, Chapter>
     */
    private function syncChapters(array $books): array
    {
        $chapters = [];

        foreach ($this->client->allChapters() as $chapterPayload) {
            $chapterId = (string) Arr::get($chapterPayload, 'id');
            $bookId = (string) Arr::get($chapterPayload, 'book_id', Arr::get($chapterPayload, 'book.id'));
            $book = $books[$bookId] ?? null;

            if ($chapterId !== '' && $book) {
                $chapters[$chapterId] = $this->upsertChapter($chapterPayload, $book);
            }
        }

        return $chapters;
    }

    private function chapterForPage(array $page, ?Book $book, array $hierarchy): ?Chapter
    {
        $chapterId = Arr::get($page, 'chapter_id', Arr::get($page, 'chapter.id'));

        if (! $book || ! $chapterId) {
            return null;
        }

        $chapterId = (string) $chapterId;

        if (isset($hierarchy['chapters'][$chapterId])) {
            return $hierarchy['chapters'][$chapterId];
        }

        $chapterPayload = Arr::get($page, 'chapter');

        if (! is_array($chapterPayload)) {
            $chapterPayload = $this->client->readChapter($chapterId);
        }

        return $this->upsertChapter($chapterPayload, $book);
    }

    private function upsertChapter(array $chapterPayload, Book $book): Chapter
    {
        $chapterId = (string) Arr::get($chapterPayload, 'id');
        $slug = $this->sourceSlug('bookstack-chapter', $chapterPayload);
        $chapter = $this->findChapterBySourceOrSlug('chapter', $chapterId, $slug) ?? new Chapter;

        $chapter->forceFill([
            'book_id' => $book->id,
            'name' => (string) Arr::get($chapterPayload, 'name', 'Chapter '.$chapterId),
            'slug' => $slug,
            'description' => $this->descriptionFromPayload($chapterPayload),
            'priority' => (int) Arr::get($chapterPayload, 'priority', 0),
            'source_system' => 'book_stack',
            'source_type' => 'chapter',
            'source_id' => $chapterId,
            'source_url' => $this->chapterUrl($chapterPayload, $book),
            'source_checksum' => $this->payloadChecksum($chapterPayload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($chapterPayload),
            'sync_status' => 'synced',
            'source_payload' => Arr::only($chapterPayload, ['id', 'name', 'slug', 'description', 'description_html', 'priority']),
        ])->save();

        return $chapter;
    }

    private function findShelfBySourceOrSlug(string $sourceType, string $sourceId, string $slug): ?Shelf
    {
        // Source metadata is authoritative, but slug fallback repairs older partial imports.
        return Shelf::query()
            ->where('source_system', 'book_stack')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first()
            ?? Shelf::query()->where('slug', $slug)->first();
    }

    private function findBookBySourceOrSlug(string $sourceType, string $sourceId, string $slug): ?Book
    {
        return Book::query()
            ->where('source_system', 'book_stack')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first()
            ?? Book::query()->where('slug', $slug)->first();
    }

    private function findChapterBySourceOrSlug(string $sourceType, string $sourceId, string $slug): ?Chapter
    {
        return Chapter::query()
            ->where('source_system', 'book_stack')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first()
            ?? Chapter::query()->where('slug', $slug)->first();
    }

    private function checksum(array $page): string
    {
        return hash('sha256', json_encode([
            'name' => Arr::get($page, 'name'),
            'html' => Arr::get($page, 'html'),
            'markdown' => Arr::get($page, 'markdown'),
            'draft' => Arr::get($page, 'draft'),
            'updated_at' => Arr::get($page, 'updated_at'),
            'tags' => Arr::get($page, 'tags', []),
        ], JSON_THROW_ON_ERROR));
    }

    private function articleSlug(array $page): string
    {
        $bookSlug = (string) Arr::get($page, 'book.slug', Arr::get($page, 'book_slug', 'bookstack'));
        $pageSlug = (string) Arr::get($page, 'slug', Arr::get($page, 'id'));

        return Str::slug('bookstack-'.$bookSlug.'-'.$pageSlug.'-'.Arr::get($page, 'id'));
    }

    private function sourceSlug(string $prefix, array $payload): string
    {
        return Str::slug($prefix.'-'.Arr::get($payload, 'slug', Arr::get($payload, 'name', Arr::get($payload, 'id'))).'-'.Arr::get($payload, 'id'));
    }

    private function descriptionFromPayload(array $payload): ?string
    {
        $description = trim((string) Arr::get($payload, 'description', ''));

        if ($description !== '') {
            return $description;
        }

        $descriptionHtml = trim((string) Arr::get($payload, 'description_html', ''));

        return $descriptionHtml !== '' ? $this->plainTextFromHtml($descriptionHtml) : null;
    }

    private function plainTextFromHtml(string $html): string
    {
        $text = trim(html_entity_decode(strip_tags($html)));

        return $text !== '' ? $text : 'Imported from BookStack without editable markdown content.';
    }

    private function sourceUrl(array $page): ?string
    {
        $bookSlug = Arr::get($page, 'book.slug', Arr::get($page, 'book_slug'));
        $pageSlug = Arr::get($page, 'slug');

        if (! $bookSlug || ! $pageSlug) {
            return null;
        }

        return rtrim((string) $this->integration->server, '/').'/books/'.$bookSlug.'/page/'.$pageSlug;
    }

    private function shelfUrl(array $payload): ?string
    {
        $slug = Arr::get($payload, 'slug');

        return $slug ? rtrim((string) $this->integration->server, '/').'/shelves/'.$slug : null;
    }

    private function bookUrl(array $payload): ?string
    {
        $slug = Arr::get($payload, 'slug');

        return $slug ? rtrim((string) $this->integration->server, '/').'/books/'.$slug : null;
    }

    private function chapterUrl(array $payload, Book $book): ?string
    {
        $slug = Arr::get($payload, 'slug');

        if (! $slug || ! $book->source_payload || ! isset($book->source_payload['slug'])) {
            return null;
        }

        return rtrim((string) $this->integration->server, '/').'/books/'.$book->source_payload['slug'].'/chapter/'.$slug;
    }

    private function sourceUpdatedAt(array $page): ?Carbon
    {
        $updatedAt = Arr::get($page, 'updated_at');

        return $updatedAt ? Carbon::parse($updatedAt) : null;
    }

    /**
     * Keep only source metadata needed for debugging and future hierarchy mapping.
     *
     * @return array<string, mixed>
     */
    private function sourcePayload(array $page): array
    {
        return Arr::only($page, [
            'id',
            'book_id',
            'chapter_id',
            'slug',
            'priority',
            'revision_count',
            'template',
            'editor',
            'book',
            'chapter',
            'tags',
        ]);
    }

    private function payloadChecksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
